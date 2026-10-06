<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
use GnuCms\Repository\NotificationRepository;
use GnuCms\Tests\Support\CollectingMailer;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class OrderNotificationTest extends WebTestCase
{
    private function member(App $app, string $email = 'buyer@example.test', bool $admin = false): int
    {
        $id = (int) $app->users()->create($email, password_hash('member-password-1', PASSWORD_DEFAULT),
            ($admin ? '관리자 ' : '구매자 ') . strstr($email, '@', true), $admin);
        $app->users()->verifyEmail($id);
        return $id;
    }

    private function login(App $app, string $email = 'buyer@example.test'): void
    {
        $this->get($app, '/login');
        self::assertSame(303, $this->post($app, '/login', ['csrf_token' => $_SESSION['csrf_token'], 'email' => $email, 'password' => 'member-password-1'])->getStatusCode());
        self::assertSame(200, $this->get($app, '/notifications')->getStatusCode());
    }

    private function paidOrder(App $app, int $user): array
    {
        foreach (['order_pending', 'order_paid', 'order_confirmed', 'order_shipped', 'order_completed'] as $event) {
            $app->notifySettings()->save($event, ['delivery_choice' => '1']);
        }
        $shop = $app->shop();
        $category = $shop->categories->save(['name' => '테스트', 'parent_id' => '', 'active' => '1',
            'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
        $product = $shop->products->save(['code' => 'P' . bin2hex(random_bytes(4)), 'name' => '테스트 상품', 'category_id' => (string) $category,
            'price' => '10000', 'stock' => '5', 'active' => '1', 'summary' => '', 'description' => ''], []);
        $cart = $shop->cart->add([], ['product_id' => $product, 'quantity' => 1]);
        $order = $shop->orders->place($cart, ['buyer_name' => '테스트', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000',
            'recipient' => '테스트', 'recipient_phone' => '010-0000-0000', 'postcode' => '04524', 'address' => '테스트 주소',
            'address_detail' => '', 'delivery_note' => '', 'agree' => '1'], bin2hex(random_bytes(32)), bin2hex(random_bytes(32)),
            $user, $shop->cart->quote($cart, [], true)['fingerprint']);
        return $shop->orders->confirmDeposit((int) $order['id'], 'admin');
    }

    private function rows(App $app): array
    {
        return $app->db()->select('SELECT id, user_id, kind, order_id, is_read FROM ' . $app->db()->table('notifications') . ' ORDER BY id');
    }

    private function reject(callable $fn): void
    {
        try { $fn(); self::fail('거절해야 합니다.'); }
        catch (DomainError $e) { self::assertContains($e->status(), [404, 422]); }
    }

    #[DataProvider('connectionProvider')]
    public function testThreeStatesShareTheInboxAndDoNotSendMail(array $config): void
    {
        $app = $this->makeApp($config);
        $mailer = new CollectingMailer(); $app->setMailer($mailer);
        $user = $this->member($app); $this->login($app);
        $order = $this->paidOrder($app, $user); $id = (int) $order['id'];
        self::assertSame(['order_pending', 'order_paid'], array_column($this->rows($app), 'kind'));
        foreach (['paid' => 'confirmed', 'confirmed' => 'shipped', 'shipped' => 'completed'] as $from => $to) {
            $app->shop()->orders->transition($id, $from, $to, 'admin', ['carrier' => 'CJ대한통운', 'tracking_number' => '12345678']);
            $this->reject(fn () => $app->shop()->orders->transition($id, $from, $to, 'admin', ['carrier' => 'CJ대한통운', 'tracking_number' => '12345678']));
        }
        self::assertSame(['order_pending', 'order_paid', 'order_confirmed', 'order_shipped', 'order_completed'], array_column($this->rows($app), 'kind'));
        self::assertSame([(string) $user], array_values(array_unique(array_column($this->rows($app), 'user_id'))));
        self::assertSame(5, $app->notificationService()->unreadCount($app->guestAcl()));
        $body = $this->body($this->get($app, '/notifications'));
        self::assertStringContainsString('알림 5개', $body);
        foreach (['상품을 준비 중', '상품이 배송 중', '배송이 완료'] as $text) self::assertStringContainsString($text, $body);
        self::assertSame(5, $app->notificationService()->unreadCount($app->guestAcl()), '목록 열기는 읽음 처리하지 않는다');
        self::assertSame([], $mailer->messages, '주문 상태는 외부 메일을 보내지 않는다');
        $notification = $this->rows($app)[0];
        $opened = $this->get($app, '/notifications/' . $notification['id']);
        self::assertSame(303, $opened->getStatusCode());
        self::assertSame('/shop/order?number=' . rawurlencode($order['number']), $opened->getHeaderLine('Location'));
        self::assertSame(4, $app->notificationService()->unreadCount($app->guestAcl()));
        self::assertSame(303, $this->post($app, '/notifications/read-all', ['csrf_token' => $_SESSION['csrf_token']])->getStatusCode());
        self::assertSame(0, $app->notificationService()->unreadCount($app->guestAcl()));
    }

    #[DataProvider('connectionProvider')]
    public function testOuterRollbackAndFailedCsvLeaveNoNewNotification(array $config): void
    {
        $app = $this->makeApp($config); $order = $this->paidOrder($app, $this->member($app)); $id = (int) $order['id'];
        try {
            $app->db()->transaction(function () use ($app, $id): void {
                $app->shop()->orders->transition($id, 'paid', 'confirmed', 'admin');
                throw new \RuntimeException('isolated rollback');
            });
            self::fail('rollback expected');
        } catch (\RuntimeException $e) { self::assertSame('isolated rollback', $e->getMessage()); }
        self::assertSame('paid', $app->shop()->orders->get($id)['status']);
        self::assertSame(['order_pending', 'order_paid'], array_column($this->rows($app), 'kind'));
        $app->shop()->orders->transition($id, 'paid', 'confirmed', 'admin');
        $csv = "주문번호,택배사,운송장번호\n{$order['number']},CJ대한통운,12345678\nmissing-order,CJ대한통운,12345679\n";
        $this->reject(fn () => $app->shop()->fulfillment->importAndShip($csv, 'admin'));
        self::assertSame('confirmed', $app->shop()->orders->get($id)['status']);
        self::assertCount(3, $this->rows($app));
        self::assertSame(1, $app->shop()->fulfillment->importAndShip("주문번호,택배사,운송장번호\n{$order['number']},CJ대한통운,12345678\n", 'admin'));
        self::assertSame(['order_pending', 'order_paid', 'order_confirmed', 'order_shipped'], array_column($this->rows($app), 'kind'));
    }

    #[DataProvider('connectionProvider')]
    public function testOtherMembersAndForgedTargetsAreRejected(array $config): void
    {
        $app = $this->makeApp($config); $owner = $this->member($app); $other = $this->member($app, 'other@example.test');
        $order = $this->paidOrder($app, $owner); $app->shop()->orders->transition((int) $order['id'], 'paid', 'confirmed', 'admin');
        $this->login($app, 'other@example.test');
        self::assertSame(404, $this->get($app, '/notifications/' . $this->rows($app)[0]['id'])->getStatusCode());
        self::assertSame(0, $app->notificationService()->unreadCount($app->guestAcl()));
        $repo = new NotificationRepository($app->db());
        $repo->recordOrderStatus((int) $order['id'], $other, 'shipped', $order['number']);
        $forged = $this->rows($app)[3];
        self::assertSame(404, $this->get($app, '/notifications/' . $forged['id'])->getStatusCode());
        self::assertSame(1, $app->notificationService()->unreadCount($app->guestAcl()), '잘못된 대상은 읽음도 바꾸지 않는다');
        self::assertSame(404, $this->get($app, '/shop/order?number=' . rawurlencode($order['number']))->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testAdminListAndDetailUseTheSameNotifications(array $config): void
    {
        $app = $this->makeApp($config); $owner = $this->member($app); $this->member($app, 'admin@example.test', true);
        $order = $this->paidOrder($app, $owner); $this->login($app, 'admin@example.test');
        foreach ([['/admin/shop/orders', 'paid', 'confirmed'], ['/admin/shop/orders/detail', 'confirmed', 'shipped'], ['/admin/shop/orders', 'shipped', 'completed']] as [$path, $from, $to]) {
            $this->get($app, $path . '?id=' . $order['id']);
            $response = $this->post($app, $path, ['csrf_token' => $_SESSION['csrf_token'], 'id' => $order['id'], 'action' => 'transition',
                'from' => $from, 'status' => $to, 'carrier' => 'CJ대한통운', 'tracking_number' => '12345678']);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame($to, $app->shop()->orders->get((int) $order['id'])['status']);
        }
        self::assertSame(['order_pending', 'order_paid', 'order_confirmed', 'order_shipped', 'order_completed'], array_column($this->rows($app), 'kind'));
    }

    #[DataProvider('connectionProvider')]
    public function testLegacyNotificationMigrationIsIdempotentAndPreservesComments(array $config): void
    {
        $app = $this->makeApp($config); $user = $this->member($app);
        $repo = new NotificationRepository($app->db());
        $repo->create(['user_id' => (string) $user, 'kind' => 'comment', 'post_id' => 123, 'comment_id' => 456, 'actor_name' => '작성자', 'subject' => '기존 글']);
        $table = $app->db()->table('notifications');
        $app->db()->execute('ALTER TABLE ' . $table . ' DROP COLUMN order_id, MODIFY COLUMN post_id BIGINT NOT NULL');
        $schema = new Schema($app->db()); $schema->migrateNotifications(); $schema->migrateNotifications();
        $row = $repo->paginate((string) $user, 1, 20)['items'][0];
        self::assertSame(123, $row['post_id']); self::assertSame(456, $row['comment_id']); self::assertNull($row['order_id']); self::assertFalse($row['is_read']);
        $order = $this->paidOrder($app, $user); $app->shop()->orders->transition((int) $order['id'], 'paid', 'confirmed', 'admin');
        $this->login($app);
        self::assertSame(4, $app->notificationService()->unreadCount($app->guestAcl()));
        $body = $this->body($this->get($app, '/notifications'));
        self::assertStringContainsString('내 글에 댓글을 달았습니다', $body); self::assertStringContainsString('상품을 준비 중', $body);
    }

    #[DataProvider('connectionProvider')]
    public function testInactiveMembersDoNotGetOrderNotifications(array $config): void
    {
        $app = $this->makeApp($config); $user = $this->member($app); $order = $this->paidOrder($app, $user);
        $app->db()->update('users', ['status' => 'blocked'], 'id = :id', ['id' => $user]);
        $app->shop()->orders->transition((int) $order['id'], 'paid', 'confirmed', 'admin');
        self::assertSame(['order_pending', 'order_paid'], array_column($this->rows($app), 'kind'));
        self::assertSame('confirmed', $app->shop()->orders->get((int) $order['id'])['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testFailedInboxWriteRollsBackTheStatusAndHistory(array $config): void
    {
        $app = $this->makeApp($config); $order = $this->paidOrder($app, $this->member($app)); $id = (int) $order['id'];
        $before = $app->shop()->orders->get($id)['history'];
        $app->db()->execute('DROP TABLE ' . $app->db()->table('notifications'));
        try {
            $app->shop()->orders->transition($id, 'paid', 'confirmed', 'admin');
            self::fail('알림 저장 실패는 상태 변경도 취소해야 한다');
        } catch (DomainError $e) { self::assertSame(500, $e->status()); }
        self::assertSame('paid', $app->shop()->orders->get($id)['status']);
        self::assertSame($before, $app->shop()->orders->get($id)['history']);
    }
}

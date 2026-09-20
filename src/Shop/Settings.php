<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;

final class Settings
{
    public const TYPES = ['hit', 'new', 'recommend', 'discount', 'popular'];
    public const TYPE_LABELS = ['hit' => '히트상품', 'new' => '최신상품', 'recommend' => '추천상품', 'discount' => '할인상품', 'popular' => '인기상품'];
    public const TYPE_COLUMNS = ['hit' => 'is_hit', 'new' => 'is_new', 'recommend' => 'is_recommended', 'discount' => 'is_discount', 'popular' => 'is_popular'];

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer) {}

    public static function defaults(): array
    {
        $block = ['columns' => 4, 'rows' => 1, 'image_width' => 200, 'image_height' => 0];
        $main = [];
        foreach (self::TYPES as $type) $main[$type] = ['use' => $type !== 'popular'] + $block;
        return [
            'visible' => true,
            'banner' => HomeBanner::defaults(),
            'main' => $main,
            'category' => ['columns' => 3, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'type' => ['columns' => 4, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'search' => ['columns' => 4, 'rows' => 5, 'image_width' => 200, 'image_height' => 0],
            'related' => ['use' => true, 'columns' => 4, 'image_width' => 100, 'image_height' => 0],
            'detail' => ['image_width' => 400, 'image_height' => 0],
            'show_tax' => false,
            'shipping' => ['content' => '', 'fee' => 0, 'free_minimum' => 0],
            'order_notice' => '주문 접수 후 판매자가 결제 및 배송을 안내합니다. 이 화면에서는 결제되지 않습니다.',
            'exchange' => ['content' => ''],
            'payment' => ['environment' => 'live',
                'manual' => ['enabled' => false, 'bank' => '', 'account' => '', 'holder' => ''],
                'deadline_hours' => ['card' => 1, 'virtual_account' => 72, 'manual_transfer' => 72]],
        ];
    }

    /** 매번 저장소에서 다시 읽는다. 캐시를 두면 이 인스턴스가 쓰기 전에 다른 인스턴스가
     *  저장한 값을 계속 못 보게 되어(요청마다 새 Service를 만드는 확장 구조와 맞지 않는다) 두지 않는다. */
    public function all(): array
    {
        $row = $this->store->selectOne('SELECT payload FROM ' . $this->store->table('yc_settings') . " WHERE id = 'settings'");
        $saved = $row === null ? [] : json_decode((string) $row['payload'], true, 8, JSON_THROW_ON_ERROR);
        return array_replace_recursive(self::defaults(), is_array($saved) ? $saved : []);
    }

    public function block(string $name): array
    {
        $all = $this->all();
        [$section, $type] = array_pad(explode('.', $name, 2), 2, null);
        return $type === null ? $all[$section] : $all[$section][$type];
    }

    public function save(array $input, ?string $bannerImage = null): array
    {
        $errors = [];
        $int = static function (string $key, int $min, int $max) use ($input, &$errors): int {
            $value = $input[$key] ?? null;
            if (!is_string($value) && !is_int($value) || !preg_match('/^(0|[1-9][0-9]{0,6})$/D', (string) $value) || (int) $value < $min || (int) $value > $max) {
                $errors[$key] = $min . '~' . $max . ' 사이의 정수를 입력해 주세요.';
                return $min;
            }
            return (int) $value;
        };
        $bool = static fn (string $key): bool => ($input[$key] ?? '') === '1';
        $settings = ['main' => []];
        foreach (self::TYPES as $type) {
            $settings['main'][$type] = ['use' => $bool('main_' . $type . '_use'), 'columns' => $int('main_' . $type . '_columns', 1, 12),
                'rows' => $int('main_' . $type . '_rows', 1, 50), 'image_width' => $int('main_' . $type . '_image_width', 0, 2000),
                'image_height' => $int('main_' . $type . '_image_height', 0, 2000)];
        }
        foreach (['category', 'type', 'search'] as $section) {
            $settings[$section] = ['columns' => $int($section . '_columns', 1, 12), 'rows' => $int($section . '_rows', 1, 50),
                'image_width' => $int($section . '_image_width', 0, 2000), 'image_height' => $int($section . '_image_height', 0, 2000)];
        }
        $settings['related'] = ['use' => $bool('related_use'), 'columns' => $int('related_columns', 1, 12),
            'image_width' => $int('related_image_width', 0, 2000), 'image_height' => $int('related_image_height', 0, 2000)];
        $settings['detail'] = ['image_width' => $int('detail_image_width', 0, 2000), 'image_height' => $int('detail_image_height', 0, 2000)];
        $settings['show_tax'] = $bool('show_tax');
        foreach (['shipping', 'exchange'] as $key) {
            $content = $input[$key . '_content'] ?? '';
            if (!is_string($content) || strlen($content) > 60000) { $errors[$key . '_content'] = '내용이 너무 깁니다.'; $content = ''; }
            $settings[$key] = ['content' => $this->sanitizer->clean($content)];
        }
        // 이전 테마의 설정 폼에서도 새 필드가 누락되면 저장된 값을 보존한다.
        $previous = $this->all();
        $settings['banner'] = HomeBanner::validate($this->store, $input, $previous['banner'], $bannerImage);
        foreach (['fee', 'free_minimum'] as $key) {
            $settings['shipping'][$key] = array_key_exists('shipping_' . $key, $input) ? $int('shipping_' . $key, 0, 9999999) : $previous['shipping'][$key];
        }
        $settings['order_notice'] = Input::text($input['order_notice'] ?? $previous['order_notice'], 'order_notice', 2000, false);
        $settings['visible'] = array_key_exists('visible_form', $input) ? $bool('visible') : $previous['visible'];
        // 결제: 폼에 없는 값은 이전 값을 지킨다(다른 테마의 옛 폼과 같은 규칙).
        $payment = $previous['payment'];
        $environment = $input['payment_environment'] ?? null;
        if (in_array($environment, ['test', 'live'], true)) $payment['environment'] = $environment;
        if (array_key_exists('payment_manual_enabled', $input) || array_key_exists('payment_manual_account', $input)) {
            $payment['manual'] = ['enabled' => $bool('payment_manual_enabled'),
                'bank' => Input::text($input['payment_manual_bank'] ?? '', 'payment_manual_bank', 50),
                'account' => Input::text($input['payment_manual_account'] ?? '', 'payment_manual_account', 50),
                'holder' => Input::text($input['payment_manual_holder'] ?? '', 'payment_manual_holder', 50)];
            if ($payment['manual']['enabled'] && $payment['manual']['account'] === '') $errors['payment_manual_account'] = '무통장입금을 켜려면 계좌번호를 입력해 주세요.';
        }
        foreach (['card' => 72, 'virtual_account' => 720, 'manual_transfer' => 720] as $key => $max) {
            if (array_key_exists('payment_deadline_' . $key, $input)) $payment['deadline_hours'][$key] = $int('payment_deadline_' . $key, 1, $max);
        }
        $settings['payment'] = $payment;
        if ($errors !== []) throw DomainError::validation($errors);
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->store->transaction(function () use ($payload): void {
            if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_settings') . " WHERE id = 'settings'") === null) {
                $this->store->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
            } else {
                $this->store->db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
            }
        });
        return $this->all();
    }
}

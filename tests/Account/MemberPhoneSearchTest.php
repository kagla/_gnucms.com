<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\UserRepository;
use GnuCms\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 관리자 회원 검색(UserRepository::listForAdmin())에 번호를 더한다. 저장된
 * 번호는 숫자만이라 검색어도 숫자만 뽑아 비교해야 하이픈·공백을 넣어 찾아도
 * 걸린다. 숫자가 하나도 없는 검색어(이름 등)는 phone 조건 자체를 붙이지 않아야
 * 한다 — 안 그러면 `phone LIKE '%%'` 가 번호를 가진 회원을 몽땅 끌고 온다.
 */
final class MemberPhoneSearchTest extends DatabaseTestCase
{
    #[DataProvider('connectionProvider')]
    public function testFindsAMemberByDigitsWithOrWithoutHyphensOrSpaces(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '홍길동', 'phone' => '01012345678'],
            ['name' => '김철수', 'phone' => '01098765432'],
            ['name' => '번호없음', 'phone' => null],
        ]);

        self::assertSame(['홍길동'], $this->names($repository->listForAdmin('010-1234-5678')));
        self::assertSame(['홍길동'], $this->names($repository->listForAdmin('010 1234 5678')));
        self::assertSame(['홍길동'], $this->names($repository->listForAdmin('1234')));
        self::assertCount(3, $repository->listForAdmin(''));
    }

    #[DataProvider('connectionProvider')]
    public function testSearchStillMatchesNameAndEmail(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '홍길동', 'phone' => '01012345678'],
        ]);

        self::assertSame(['홍길동'], $this->names($repository->listForAdmin('홍길동')));
        self::assertSame(['홍길동'], $this->names($repository->listForAdmin('member0@example.com')));
    }

    /**
     * 숫자가 없는 검색어는 phone 조건을 붙이지 않는다. 붙이면 `phone LIKE '%%'`
     * 가 번호를 가진 모든 회원을 끌고 와, 없는 이름을 찾아도 엉뚱한 사람이 걸린다.
     */
    #[DataProvider('connectionProvider')]
    public function testANameWithNoDigitsDoesNotMatchEveryoneWithAPhone(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '홍길동', 'phone' => '01012345678'],
            ['name' => '김철수', 'phone' => '01098765432'],
        ]);

        self::assertSame([], $this->names($repository->listForAdmin('없는이름')));
    }

    /**
     * 발송 화면의 회원 고르기도 같은 번호 검색을 쓴다(스펙 §7). 번호를 들고 있는
     * 운영자가 회원관리로 가서 이름을 알아내 다시 검색할 필요가 없어야 한다.
     */
    #[DataProvider('connectionProvider')]
    public function testTheSendPickerFindsAMemberByNumberToo(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '홍길동', 'phone' => '01012345678'],
            ['name' => '김철수', 'phone' => '01098765432'],
        ]);

        self::assertSame(['홍길동'], $this->names($repository->searchActive('01012345678')));
        self::assertSame(['홍길동'], $this->names($repository->searchActive('010-1234-5678')));
        self::assertSame(['홍길동'], $this->names($repository->searchActive('홍길동')));
        self::assertSame([], $this->names($repository->searchActive('')));
    }

    /**
     * 두 검색이 일부러 다른 점은 그대로 둔다. 발송 화면은 차단·탈퇴 회원을 감추고
     * (차단된 회원은 없는 회원과 같게 다룬다), 회원 관리 목록은 관리자가 손댈 수
     * 있도록 일부러 보여 준다. 같이 쓰는 것은 "무엇과 일치하는가"뿐이다.
     */
    #[DataProvider('connectionProvider')]
    public function testThePickerHidesABlockedMemberTheManagementListStillShows(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '차단됨', 'phone' => '01012345678'],
        ]);
        $repository->setStatus((int) $repository->listForAdmin('01012345678')[0]['id'], 'blocked');

        self::assertSame([], $this->names($repository->searchActive('01012345678')),
            '발송 화면은 차단된 회원을 번호로도 찾아 주면 안 된다');
        self::assertSame(['차단됨'], $this->names($repository->listForAdmin('01012345678')),
            '회원 관리 목록은 차단된 회원을 계속 보여 줘야 한다');
    }

    /** 숫자 없는 검색어에 phone 조건을 붙이지 않는 규칙은 발송 화면 쪽에도 그대로 있어야 한다. */
    #[DataProvider('connectionProvider')]
    public function testANameWithNoDigitsDoesNotMatchEveryoneWithAPhoneInThePicker(array $config): void
    {
        $repository = $this->repositoryWithMembers($config, [
            ['name' => '홍길동', 'phone' => '01012345678'],
            ['name' => '김철수', 'phone' => '01098765432'],
        ]);

        self::assertSame([], $this->names($repository->searchActive('없는이름')));
    }

    /** @param array<int, array{name: string, phone: ?string}> $members */
    private function repositoryWithMembers(array $config, array $members): UserRepository
    {
        $db = $this->freshDatabase($config);
        $repository = new UserRepository($db);
        foreach ($members as $i => $member) {
            $id = $repository->create(
                'member' . $i . '@example.com',
                password_hash('member-password-123', PASSWORD_DEFAULT),
                $member['name'],
                false
            );
            if ($member['phone'] !== null) {
                $repository->updatePhone($id, $member['phone']);
            }
        }

        return $repository;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function names(array $rows): array
    {
        return array_map(static fn (array $row): string => (string) $row['display_name'], $rows);
    }
}

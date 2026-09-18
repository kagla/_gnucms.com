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

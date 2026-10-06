<?php
declare(strict_types=1);
namespace GnuCms\Tests\Aligo;
use GnuCms\Aligo\Dispatch;
use PHPUnit\Framework\TestCase;
final class DispatchRequestFieldsTest extends TestCase
{
    public function testAlimtalkTitleIsSingleLineWhileApprovedBodyKeepsItsNewlines(): void
    {
        $dispatch=(new \ReflectionClass(Dispatch::class))->newInstanceWithoutConstructor();
        $body="[GNUCMS] 회원가입 완료 안내\n\n가입을 환영합니다.";
        $fields=(new \ReflectionMethod(Dispatch::class,'alimtalkFields'))->invoke($dispatch,
            [['phone'=>'01000000000','body'=>$body,'name'=>'테스트','fallback_body'=>'가입완료']],
            ['tpl_code'=>'APPROVED_TEST','failover'=>true],['senderkey'=>'TEST','sender'=>'0212345678','test_mode'=>true],null);
        self::assertSame($body,$fields['message_1']);
        self::assertStringNotContainsString("\n",$fields['subject_1']);
        self::assertLessThanOrEqual(20,mb_strlen($fields['subject_1']));
        self::assertSame('Y',$fields['testMode']);
        self::assertSame('가입완료',$fields['fmessage_1']);
        self::assertSame('가입완료',$fields['fsubject_1']);
    }
}

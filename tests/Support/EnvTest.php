<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Support;

use PHPUnit\Framework\TestCase;
use StandardBoard\Http\ApiError;
use StandardBoard\Support\Env;

final class EnvTest extends TestCase
{
    public function testParsesSimpleAssignments(): void
    {
        $values = Env::parse("DB_DSN=sqlite:/tmp/a.sqlite\nAUTH_TTL=7200\n");

        $this->assertSame(['DB_DSN' => 'sqlite:/tmp/a.sqlite', 'AUTH_TTL' => '7200'], $values);
    }

    public function testIgnoresBlankLinesAndWholeLineComments(): void
    {
        $values = Env::parse("# 주석\n\n   \n\t# 들여쓴 주석\nDEBUG=true\n");

        $this->assertSame(['DEBUG' => 'true'], $values);
    }

    public function testHashInsideValueIsNotAComment(): void
    {
        // 인라인 주석을 지원하면 비밀번호의 # 뒤가 잘려 나간다. 지원하지 않는 이유다.
        $values = Env::parse("DB_PASSWORD=p@ss#word\n");

        $this->assertSame('p@ss#word', $values['DB_PASSWORD']);
    }

    public function testValueMayContainEqualsSign(): void
    {
        $values = Env::parse("DB_DSN=mysql:host=localhost;dbname=board\n");

        $this->assertSame('mysql:host=localhost;dbname=board', $values['DB_DSN']);
    }

    public function testExportPrefixIsAccepted(): void
    {
        $this->assertSame(['DEBUG' => 'false'], Env::parse("export DEBUG=false\n"));
    }

    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $values = Env::parse("  AUTH_SECRET =  abc123   \n");

        $this->assertSame(['AUTH_SECRET' => 'abc123'], $values);
    }

    public function testDoubleQuotesPreserveSpacesAndDecodeEscapes(): void
    {
        $values = Env::parse('GREETING="  하나\ttwo\nthree \"인용\" \\\\ 끝  "' . "\n");

        $this->assertSame("  하나\ttwo\nthree \"인용\" \\ 끝  ", $values['GREETING']);
    }

    public function testSingleQuotesAreLiteral(): void
    {
        $values = Env::parse("RAW='a\\nb #not-comment'\n");

        $this->assertSame('a\nb #not-comment', $values['RAW']);
    }

    public function testEmptyValueStaysEmptyString(): void
    {
        $this->assertSame(['DB_USERNAME' => ''], Env::parse("DB_USERNAME=\n"));
    }

    public function testLastAssignmentWins(): void
    {
        $this->assertSame(['DEBUG' => 'false'], Env::parse("DEBUG=true\nDEBUG=false\n"));
    }

    public function testCarriageReturnsAreStripped(): void
    {
        // FTP 로 올린 .env 는 CRLF 인 경우가 흔하다.
        $this->assertSame(['DEBUG' => 'true'], Env::parse("DEBUG=true\r\n"));
    }

    public function testMalformedLineIsReportedWithLineNumber(): void
    {
        try {
            Env::parse("DEBUG=true\n이건 대입문이 아니다\n");
            $this->fail('오류가 나야 한다');
        } catch (ApiError $e) {
            $this->assertStringContainsString('2', $e->getMessage());
        }
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(ApiError::class);
        Env::parse("9LIVES=cat\n");
    }

    public function testMissingFileGivesEmptyArray(): void
    {
        $this->assertSame([], Env::parseFile(sys_get_temp_dir() . '/standard-board-no-such-env-file'));
    }

    public function testParseFileReadsFromDisk(): void
    {
        $path = sys_get_temp_dir() . '/standard-board-env-' . bin2hex(random_bytes(4));
        file_put_contents($path, "DEBUG=true\n");

        try {
            $this->assertSame(['DEBUG' => 'true'], Env::parseFile($path));
        } finally {
            @unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IntegrationBootstrapOrderTest extends TestCase
{
    public function testSchemaIsLoadedOnlyAfterTheSuiteLockIsAcquired(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../Integration/IntegrationTestCase.php');
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $lock = strpos($code, 'SELECT GET_LOCK(');
        $failedLock = strpos($code, "if ((int)(\$acquired['ok'] ?? 0) !== 1)");
        $load = strpos($code, 'self::loadSchema($pdo)');
        $this->assertNotFalse($lock);
        $this->assertNotFalse($failedLock);
        $this->assertNotFalse($load);
        $this->assertLessThan($failedLock, $lock);
        $this->assertLessThan($load, $failedLock, 'ห้าม DROP/CREATE ตารางก่อนรับและตรวจล็อก');
    }
}

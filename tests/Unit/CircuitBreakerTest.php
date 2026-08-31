<?php
declare(strict_types=1);

namespace TypechoSuite\Tests;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\SuiteSearch\CircuitBreaker;

final class CircuitBreakerTest extends TestCase
{
    public function testStateIsSharedAcrossInstancesAndExpires(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'suite-circuit-');
        self::assertIsString($path);
        @unlink($path);
        $now = 1000;
        $clock = static function () use (&$now): int { return $now; };
        $first = new CircuitBreaker($path, 30, $clock);
        $second = new CircuitBreaker($path, 30, $clock);

        self::assertFalse($first->isOpen());
        $first->trip();
        self::assertTrue($second->isOpen());
        $now = 1030;
        self::assertFalse($second->isOpen());
        $second->clear();
        self::assertFileDoesNotExist($path);
    }
}

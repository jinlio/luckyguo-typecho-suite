<?php
declare(strict_types=1);

namespace TypechoSuite\Tests;

use PHPUnit\Framework\TestCase;

final class TypechoSmokeTest extends TestCase
{
    public function testDisposableTypechoRootContainsCoreAndSuiteEntrypoints(): void
    {
        if (!getenv('TYPECHO_ROOT')) {
            $this->markTestSkipped('Set TYPECHO_ROOT to a disposable Typecho installation for this test.');
        }
        $root = rtrim((string) getenv('TYPECHO_ROOT'), '/');
        $this->assertFileExists($root . '/config.inc.php');
        $this->assertFileExists($root . '/var/Typecho/Loader.php');
        $this->assertDirectoryExists($root . '/usr/plugins');
    }

    public function testMySql8ServiceIsReachableWhenConfigured(): void
    {
        $host = getenv('DB_HOST');
        if (!$host) {
            $this->markTestSkipped('Set DB_HOST/DB_PORT/DB_DATABASE for the MySQL integration smoke test.');
        }
        if (!class_exists('mysqli')) {
            $this->fail('mysqli extension is required for the MySQL integration smoke test.');
        }

        $port = (int) (getenv('DB_PORT') ?: 3306);
        $user = (string) (getenv('DB_USERNAME') ?: 'root');
        $password = (string) (getenv('DB_PASSWORD') ?: 'root');
        $database = (string) (getenv('DB_DATABASE') ?: 'typecho_test');
        $connection = @new \mysqli($host, $user, $password, $database, $port);
        $this->assertSame(0, $connection->connect_errno, $connection->connect_error ?: 'MySQL connection failed');

        $result = $connection->query('SELECT VERSION() AS version');
        $this->assertNotFalse($result);
        $row = $result ? $result->fetch_assoc() : null;
        $this->assertNotEmpty($row['version'] ?? null);
        $major = explode('.', (string) ($row['version'] ?? ''), 2)[0];
        $this->assertSame('8', $major, 'The integration service must be MySQL 8.x.');
        $connection->close();
    }
}

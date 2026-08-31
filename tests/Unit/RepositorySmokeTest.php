<?php
declare(strict_types=1);

namespace TypechoSuite\Tests;

use PHPUnit\Framework\TestCase;

final class RepositorySmokeTest extends TestCase
{
    public function testPublicPackageDoesNotContainCredentialsOrPrivateDeploymentPaths(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root . '/README.md');
        $this->assertFileExists($root . '/README.zh-CN.md');
        $this->assertFileExists($root . '/deploy/suite-doctor.php');
        $this->assertFileDoesNotExist($root . '/config.inc.php');

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'sh', 'md'], true)) {
                $files[] = $file->getPathname();
            }
        }
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/8\.136\.148\.185|zhzz20041117|GGJJ/i', $contents, $file);
        }
    }

    public function testStaticDoctorIsReadOnlyByDefault(): void
    {
        $doctor = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/suite-doctor.php');
        $this->assertStringContainsString("\$apply = false", $doctor);
        $this->assertStringContainsString("--apply", $doctor);
        $this->assertStringContainsString('DRY-RUN', $doctor);
    }

    public function testTagSlugMigrationHasRollbackGuardsAndCorePatch(): void
    {
        $root = dirname(__DIR__, 2);
        $doctor = (string) file_get_contents($root . '/deploy/tag-slug-doctor.php');
        $patch = (string) file_get_contents($root . '/patches/typecho-1.3.0-tag-slug-uniqueness.patch');
        $this->assertStringContainsString('suite_slug_validate_rollback', $doctor);
        $this->assertStringContainsString('DROP INDEX', $doctor);
        $this->assertStringContainsString('changes_sha256', $doctor);
        $this->assertStringContainsString('Common::slugName($tag)', $patch);
        $this->assertStringContainsString('concurrent writer', $patch);
    }
}

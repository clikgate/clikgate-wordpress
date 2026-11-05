<?php

declare(strict_types=1);

namespace Clikgate\WP\Tests\Unit;

use Clikgate\WP\Tests\TestCase;

final class VersionCheckTest extends TestCase
{
    private function run_check(string $arg = ''): int
    {
        $script = dirname(__DIR__, 2) . '/scripts/check-version.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ($arg !== '' ? ' ' . escapeshellarg($arg) : '') . ' 2>&1', $out, $code);
        return $code;
    }

    public function test_header_readme_and_constant_match(): void
    {
        $this->assertSame(0, $this->run_check());
        $this->assertSame(0, $this->run_check('wordpress-v' . CLIKGATE_VERSION));
        $this->assertSame(0, $this->run_check(CLIKGATE_VERSION));
    }

    public function test_a_different_tag_fails(): void
    {
        $this->assertSame(1, $this->run_check('wordpress-v9.9.9'));
        $this->assertSame(1, $this->run_check('wordpress-vnext'));
    }
}

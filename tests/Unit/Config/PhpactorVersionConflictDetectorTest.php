<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Config;

use Suzumaze\BearPhpactor\Config\PhpactorVersionConflictDetector;
use PHPUnit\Framework\TestCase;

/**
 * The broken language-server/protocol combination detector: reports only
 * language-server <= 7.0.1 with protocol >= 3.17.5, silent on anything
 * unknown.
 */
final class PhpactorVersionConflictDetectorTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixture/Config/InstalledJson';

    public function testDetectsBrokenCombination(): void
    {
        $conflict = $this->detector('broken')->brokenCombination();

        self::assertSame(
            ['language_server' => '7.0.1', 'protocol' => '3.17.5'],
            $conflict
        );
    }

    public function testSilentWhenProtocolIsOld(): void
    {
        self::assertNull($this->detector('safe-old-protocol')->brokenCombination());
    }

    public function testSilentWhenLanguageServerIsNew(): void
    {
        self::assertNull($this->detector('safe-new-server')->brokenCombination());
    }

    public function testSilentWhenInstalledJsonIsMissing(): void
    {
        $detector = new PhpactorVersionConflictDetector(self::FIXTURE . '/no-such-project');

        self::assertNull($detector->brokenCombination());
    }

    public function testSilentWhenPackageIsMissing(): void
    {
        self::assertNull($this->detector('missing-package')->brokenCombination());
    }

    public function testSilentWhenVersionIsNotComparable(): void
    {
        self::assertNull($this->detector('non-comparable')->brokenCombination());
    }

    private function detector(string $scenario): PhpactorVersionConflictDetector
    {
        return new PhpactorVersionConflictDetector(self::FIXTURE . '/' . $scenario);
    }
}

<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Config;

/**
 * Detects the broken phpactor language-server / language-server-protocol
 * version combination from a project's vendor/composer/installed.json.
 *
 * With language-server 7.0.1 or older and protocol 3.17.5 or newer,
 * textDocument/didChange never reaches the server: unsaved edits are ignored
 * and every feature answers from the didOpen text until you save, with no
 * error anywhere.
 *
 * EXPIRY: delete this class (and its test) once a fixed
 * phpactor/language-server — one that requires ^3.17.5 of the protocol — is
 * released. Remove the protocol pin from composer.json at the same time.
 *
 * When either version is missing or not numerically comparable (dev-master,
 * ...), the detector stays silent: a wrong warning is worse than none.
 */
final class PhpactorVersionConflictDetector
{
    private const LANGUAGE_SERVER = 'phpactor/language-server';

    private const PROTOCOL = 'phpactor/language-server-protocol';

    private const BROKEN_LANGUAGE_SERVER = '7.0.1';

    private const BROKEN_PROTOCOL = '3.17.5';

    public function __construct(
        private string $projectRoot,
    ) {
    }

    /**
     * The detected broken combination, or null when the combination is safe
     * or cannot be determined.
     *
     * @return array{language_server: string, protocol: string}|null
     */
    public function brokenCombination(): ?array
    {
        $versions = $this->versions();
        if ($versions === null) {
            return null;
        }

        [$languageServer, $protocol] = $versions;
        if (!version_compare($languageServer, self::BROKEN_LANGUAGE_SERVER, '<=')) {
            return null;
        }
        if (!version_compare($protocol, self::BROKEN_PROTOCOL, '>=')) {
            return null;
        }

        return ['language_server' => $languageServer, 'protocol' => $protocol];
    }

    /**
     * The two package versions from installed.json, or null when either is
     * missing or not numerically comparable.
     *
     * @return array{0: string, 1: string}|null
     */
    private function versions(): ?array
    {
        $installedJson = $this->projectRoot . '/vendor/composer/installed.json';
        if (!is_file($installedJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($installedJson), true);
        if (!is_array($data)) {
            return null;
        }

        $packages = $data['packages'] ?? null;
        if (!is_array($packages)) {
            return null;
        }

        $versions = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }
            if (!is_string($package['name']) || !is_string($package['version'])) {
                continue;
            }
            $versions[$package['name']] = $package['version'];
        }

        $languageServer = $versions[self::LANGUAGE_SERVER] ?? null;
        $protocol = $versions[self::PROTOCOL] ?? null;
        if ($languageServer === null || $protocol === null) {
            return null;
        }
        if (!$this->isComparable($languageServer) || !$this->isComparable($protocol)) {
            return null;
        }

        return [$languageServer, $protocol];
    }

    /**
     * A version is comparable when it is a plain numeric release (7.0.1,
     * v3.17.4). Dev branches (dev-master, 1.0.x-dev) are not: their content
     * is unknown, so no verdict.
     */
    private function isComparable(string $version): bool
    {
        return (bool) preg_match('/^v?\d+(\.\d+)*$/', $version);
    }
}

<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Suzumaze\BearPhpactor\Semantic\Workspace\SourceSnapshot;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;

use function array_shift;
use function in_array;
use function is_dir;
use function is_file;
use function pathinfo;
use function scandir;

use const PATHINFO_EXTENSION;

/**
 * The files context discovery reads, in the order it reads them: breadth first from the entry
 * point and source roots, skipping `vendor`, `.git` and `node_modules`, PHP files and
 * extensionless scripts only. Files a forwarder requires are queued behind what is pending.
 *
 * Listing only, no parsing, so the same walk tells a cached discovery which files exist now.
 */
final class ContextSourceWalk
{
    private const MAX_VISITS = 20000;

    /** @var list<string> */
    private array $pending;

    /** @var array<string, true> */
    private array $seen = [];

    private int $visits = 0;

    /** @param list<string> $roots absolute paths */
    public function __construct(
        private readonly WorkspaceAccessPolicy $policy,
        array $roots,
        private readonly ?SourceSnapshot $record = null,
    ) {
        $this->pending = $roots;
        foreach ($roots as $root) {
            // A root that does not exist yet would be walked once it does.
            $this->record?->isDirectory($root);
        }
    }

    /** @return array{0: string, 1: string}|null the next file, absolute and workspace-relative */
    public function next(): ?array
    {
        while ($this->pending !== [] && $this->visits < self::MAX_VISITS) {
            ++$this->visits;
            $candidate = array_shift($this->pending);
            $path = $this->policy->inspectExisting($candidate);
            if ($path->value === null || isset($this->seen[$path->value->absolute])) {
                continue;
            }
            $file = $path->value->absolute;
            $this->seen[$file] = true;
            if (is_dir($file)) {
                $this->record?->directory($file);
                foreach (scandir($file) ?: [] as $child) {
                    if (!in_array($child, ['.', '..', 'vendor', '.git', 'node_modules'], true)) {
                        $this->pending[] = $file . '/' . $child;
                    }
                }
                continue;
            }
            if (!is_file($file) || !in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', ''], true)) {
                continue;
            }

            return [$file, $path->value->relative];
        }

        return null;
    }

    public function enqueue(string $path): void
    {
        $this->pending[] = $path;
    }

    /** True while paths are left that the walk has not reached. */
    public function hasPending(): bool
    {
        return $this->pending !== [];
    }
}

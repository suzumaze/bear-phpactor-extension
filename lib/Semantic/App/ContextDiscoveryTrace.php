<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\App;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression;
use Microsoft\PhpParser\Parser;
use Suzumaze\BearPhpactor\Semantic\Workspace\SourceSnapshot;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceAccessPolicy;

use function array_keys;
use function array_unique;
use function count;
use function file_get_contents;
use function strlen;

/**
 * What one context discovery read, and which of those files its answer depends on, so a cached
 * answer can be reused after files changed that cannot affect it.
 *
 * A file matters when it names a context (`Injector::getInstance(...)`, `new Bootstrap()`), requires
 * a file from a call, or calls a callable that forwards a context; or when it could not be read.
 * Any other file can change, appear or disappear without changing the answer: a callable in it
 * forwards nothing, because forwarding needs one of those calls in its body. Saving a Resource
 * file is the usual case.
 */
final class ContextDiscoveryTrace
{
    public readonly SourceSnapshot $snapshot;

    /** @var list<string> */
    private array $roots = [];

    /** @var array<string, true> */
    private array $relevant = [];

    /** @var array<string, true> files reached through a forwarder's require */
    private array $required = [];

    /** @var array<string, true> */
    private array $forwarders = [];

    /** @var array<string, list<string>> file => callee keys, until the forwarders are known */
    private array $calls = [];

    private bool $complete = true;

    public function __construct()
    {
        $this->snapshot = new SourceSnapshot();
    }

    /** @param list<string> $roots */
    public function walk(WorkspaceAccessPolicy $policy, array $roots): ContextSourceWalk
    {
        $this->roots = $roots;

        return new ContextSourceWalk($policy, $roots, $this->snapshot);
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int}|null $signature taken before reading
     * @param Node|null $parsed null when the file could not be read or was too large
     */
    public function read(
        string $file,
        ?array $signature,
        ?string $contents,
        ?Node $parsed,
        WorkspaceAccessPolicy $policy,
    ): void {
        $this->snapshot->file($file, $signature, $contents);
        if ($parsed === null) {
            $this->relevant[$file] = true;

            return;
        }
        [$direct, $keys] = self::facts($parsed, $file, $policy);
        if ($direct) {
            $this->relevant[$file] = true;
        } elseif ($keys !== []) {
            $this->calls[$file] = $keys;
        }
    }

    public function required(string $file): void
    {
        $this->required[$file] = true;
        $this->relevant[$file] = true;
    }

    /** @param bool $complete false when the walk stopped early: the cache then never reuses it */
    public function finish(ContextForwarderIndex $forwarders, bool $complete): void
    {
        foreach ($forwarders->forwarderKeys() as $key) {
            $this->forwarders[$key] = true;
        }
        foreach ($this->calls as $file => $keys) {
            foreach ($keys as $key) {
                if (isset($this->forwarders[$key])) {
                    $this->relevant[$file] = true;
                    break;
                }
            }
        }
        $this->calls = [];
        $this->complete = $complete;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    /**
     * True when discovering again would give the same answer. Files that changed without mattering
     * are read and checked one by one, then recorded in their new state.
     */
    public function stillAnswers(WorkspaceAccessPolicy $policy, int $maxFiles): bool
    {
        $changes = $this->snapshot->changes();
        if ($changes['files'] === [] && !$changes['directories']) {
            return true;
        }
        $candidates = $changes['files'];
        $listing = null;
        if ($changes['directories']) {
            $listing = new SourceSnapshot();
            $walk = new ContextSourceWalk($policy, $this->roots, $listing);
            $current = [];
            while (count($current) < $maxFiles && ($entry = $walk->next()) !== null) {
                $current[$entry[0]] = true;
            }
            if ($walk->hasPending() || count($current) + count($this->required) >= $maxFiles) {
                return false;
            }
            foreach (array_keys($current) as $file) {
                if (!$this->snapshot->has($file)) {
                    $candidates[] = $file;
                }
            }
            foreach ($this->snapshot->filePaths() as $file) {
                if (!isset($current[$file]) && !isset($this->required[$file])) {
                    $candidates[] = $file;
                }
            }
        }
        $parser = new Parser();
        $updates = [];
        foreach (array_unique($candidates) as $file) {
            if (isset($this->relevant[$file])) {
                return false;
            }
            $signature = SourceSnapshot::stat($file);
            if ($signature === null) {
                $updates[$file] = null;
                continue;
            }
            $contents = @file_get_contents($file, false, null, 0, 1_048_577);
            if ($contents === false || strlen($contents) > 1_048_576) {
                return false;
            }
            [$direct, $keys] = self::facts($parser->parseSourceFile($contents, $file), $file, $policy);
            foreach ($keys as $key) {
                $direct = $direct || isset($this->forwarders[$key]);
            }
            if ($direct) {
                return false;
            }
            $updates[$file] = [$signature, $contents];
        }
        foreach ($updates as $file => $update) {
            if ($update === null) {
                $this->snapshot->forget($file);
            } else {
                $this->snapshot->file($file, $update[0], $update[1]);
            }
        }
        if ($listing !== null) {
            $this->snapshot->replaceDirectories($listing);
        }

        return true;
    }

    /**
     * Whether a file names a context or requires a file from a call, and the callees it calls.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private static function facts(Node $parsed, string $file, WorkspaceAccessPolicy $policy): array
    {
        $keys = [];
        foreach ($parsed->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression) {
                continue;
            }
            if (
                ContextCalls::isContextCall($node, $node->getFileContents())
                || ContextCalls::unwrap($node->callableExpression) instanceof ScriptInclusionExpression
            ) {
                return [true, []];
            }
            $key = ContextCallee::key($node, $file, $policy);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return [false, $keys];
    }
}

<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\Project;

/**
 * Bounded process-local index of Resource candidates.
 *
 * Caching is opt-in because standalone/headless callers do not necessarily have
 * a filesystem watcher. The LSP adapter enables it only when the client supports
 * watched-file registration and invalidates it from Phpactor file events.
 */
final class ResourceInventoryIndex
{
    private const DEFAULT_MAX_ENTRIES = 16;

    /** @var array<string,list<array{uri: string, file: string, fqn: string}>> */
    private array $entries = [];

    /** @var list<string> Least recently used to most recently used. */
    private array $order = [];

    /** @var array<string,array<string,string>> */
    private array $classEntries = [];

    /** @var list<string> Least recently used to most recently used. */
    private array $classOrder = [];

    public function __construct(
        private bool $enabled = false,
        private int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
    }

    /**
     * @return list<array{uri: string, file: string, fqn: string}>
     */
    public function candidates(Project $project, ?string $contextPath = null): array
    {
        if (!$this->enabled || $this->maxEntries < 1) {
            return $project->resourceClassCandidates();
        }

        $key = $this->key($project, $contextPath);
        if (isset($this->entries[$key])) {
            $this->touch($key);

            return $this->entries[$key];
        }

        $candidates = $project->resourceClassCandidates();
        $this->entries[$key] = $candidates;
        $this->touch($key);
        while (count($this->order) > $this->maxEntries) {
            $oldest = array_shift($this->order);
            if ($oldest !== null) {
                unset($this->entries[$oldest]);
            }
        }

        return $candidates;
    }

    /** @return array<string,string> Resource URI to class FQN. */
    public function classes(Project $project, ?string $contextPath = null): array
    {
        if (!$this->enabled || $this->maxEntries < 1) {
            return $project->resourceClasses();
        }

        $key = $this->key($project, $contextPath);
        if (isset($this->classEntries[$key])) {
            $this->touchClass($key);

            return $this->classEntries[$key];
        }

        $classes = $project->resourceClasses();
        $this->classEntries[$key] = $classes;
        $this->touchClass($key);
        while (count($this->classOrder) > $this->maxEntries) {
            $oldest = array_shift($this->classOrder);
            if ($oldest !== null) {
                unset($this->classEntries[$oldest]);
            }
        }

        return $classes;
    }

    public function invalidate(): void
    {
        $this->entries = [];
        $this->order = [];
        $this->classEntries = [];
        $this->classOrder = [];
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->maxEntries > 0;
    }

    private function key(Project $project, ?string $contextPath): string
    {
        return implode("\0", [
            $project->root(),
            $contextPath ?? '',
            hash('sha256', serialize($project->psr4())),
        ]);
    }

    private function touch(string $key): void
    {
        $position = array_search($key, $this->order, true);
        if ($position !== false) {
            unset($this->order[$position]);
            $this->order = array_values($this->order);
        }
        $this->order[] = $key;
    }

    private function touchClass(string $key): void
    {
        $position = array_search($key, $this->classOrder, true);
        if ($position !== false) {
            unset($this->classOrder[$position]);
            $this->classOrder = array_values($this->classOrder);
        }
        $this->classOrder[] = $key;
    }
}

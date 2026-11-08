<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Workspace;

use function array_key_exists;
use function array_keys;
use function clearstatcache;
use function file_exists;
use function file_get_contents;
use function hash;
use function is_array;
use function is_dir;
use function scandir;
use function sort;
use function stat;
use function time;

/**
 * What a computation read from disk, recorded where it was read, so a later call can tell
 * whether reading again would give the same input. No watcher is needed: the check is a
 * `stat()` per file and a `scandir()` per directory.
 *
 * A file is compared by modification time, change time, size and inode. Those times are in
 * whole seconds, so a file written in the same second it was read could change again without
 * its time moving; such a file is compared by the hash of the contents that were used instead,
 * as git does for racily clean files.
 */
final class SourceSnapshot
{
    /** @var array<string, array{0: int, 1: int, 2: int, 3: int, 4: ?string}|null> path => signature, null when missing */
    private array $files = [];

    /** @var array<string, list<string>|null> directory => sorted entries, null when missing */
    private array $directories = [];

    /** @var array<string, bool> path => whether it was a directory */
    private array $presence = [];

    /**
     * The signature to pass to {@see file()}: take it before reading, so a write between the
     * read and the record shows up as a change later.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function stat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return is_array($stat) ? [$stat['mtime'], $stat['ctime'], $stat['size'], $stat['ino']] : null;
    }

    /**
     * Records a file as read, or as missing when `$signature` is null.
     *
     * @param array{0: int, 1: int, 2: int, 3: int}|null $signature from {@see stat()}, taken before reading
     * @param string|null $contents what was read; hashed only when the file is racily new
     */
    public function file(string $path, ?array $signature, ?string $contents = null): void
    {
        if ($signature === null) {
            $this->files[$path] = null;

            return;
        }
        $racy = $signature[0] >= time() - 1 || $signature[1] >= time() - 1;
        $hash = $racy ? hash('xxh128', $contents ?? (string) @file_get_contents($path)) : null;
        $this->files[$path] = [...$signature, $hash];
    }

    /** Records a file the computation looked for and did not find, or found without reading it. */
    public function probe(string $path): void
    {
        if (!array_key_exists($path, $this->files)) {
            $this->file($path, self::stat($path));
        }
    }

    /** Records a directory whose entries the computation listed. */
    public function directory(string $path): void
    {
        $this->directories[$path] = self::entries($path);
    }

    /** Records whether a path was an existing directory. */
    public function isDirectory(string $path): void
    {
        $this->presence[$path] = is_dir($path);
    }

    public function merge(self $other): void
    {
        $this->files = $other->files + $this->files;
        $this->directories = $other->directories + $this->directories;
        $this->presence = $other->presence + $this->presence;
    }

    /**
     * The recorded files whose state differs now, and whether any recorded directory listing
     * or directory presence changed.
     *
     * @return array{files: list<string>, directories: bool}
     */
    public function changes(): array
    {
        $files = [];
        foreach ($this->files as $path => $recorded) {
            if (!$this->same($path, $recorded)) {
                $files[] = $path;
            }
        }
        $directories = false;
        foreach ($this->directories as $path => $entries) {
            if (self::entries($path) !== $entries) {
                $directories = true;
                break;
            }
        }
        foreach ($this->presence as $path => $wasDirectory) {
            clearstatcache(true, $path);
            if (is_dir($path) !== $wasDirectory) {
                $directories = true;
                break;
            }
        }

        return ['files' => $files, 'directories' => $directories];
    }

    public function unchanged(): bool
    {
        return $this->changes() === ['files' => [], 'directories' => false];
    }

    /** @return list<string> */
    public function filePaths(): array
    {
        return array_keys($this->files);
    }

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    /** Takes the directory listings and presences of another record in place of these. */
    public function replaceDirectories(self $from): void
    {
        $this->directories = $from->directories;
        $this->presence = $from->presence;
    }

    /** Drops a file from the record, for a caller that re-checks it another way. */
    public function forget(string $path): void
    {
        unset($this->files[$path]);
    }

    /** @param array{0: int, 1: int, 2: int, 3: int, 4: ?string}|null $recorded */
    private function same(string $path, ?array $recorded): bool
    {
        $now = self::stat($path);
        if ($recorded === null || $now === null) {
            return $recorded === null && $now === null && !file_exists($path);
        }
        if ($recorded[4] !== null) {
            $contents = @file_get_contents($path);

            return $contents !== false && hash('xxh128', $contents) === $recorded[4];
        }

        return [$recorded[0], $recorded[1], $recorded[2], $recorded[3]] === $now;
    }

    /** @return list<string>|null */
    private static function entries(string $path): ?array
    {
        clearstatcache(true, $path);
        if (!is_dir($path)) {
            return null;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            return null;
        }
        sort($entries);

        return $entries;
    }
}

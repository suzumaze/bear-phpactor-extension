<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\EnumDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Parser;
use Throwable;

/**
 * Locates class declarations through Composer autoload metadata read as data.
 *
 * The root `composer.json` and `vendor/composer/installed.json` are parsed as JSON;
 * `vendor/autoload.php` is never included, so autoload `files` never run.
 */
final class ClassSourceIndex
{
    public const DECLARATION = '/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m';

    /** @var array<string, list<string>> namespace prefix => absolute directories */
    private array $psr4 = [];

    /** @var array<string, list<string>> namespace prefix => absolute directories */
    private array $psr0 = [];

    /** @var list<string> */
    private array $classmapPaths = [];

    /** @var array<string, string>|null lowercase FQCN => absolute file */
    private ?array $classmap = null;

    /** @var array<string, ClassSource|null> lowercase FQCN => source */
    private array $classes = [];

    /** @var array<string, bool> */
    private array $parsedFiles = [];

    private Parser $parser;

    public function __construct(private readonly string $root, private readonly ?string $evidenceRoot = null)
    {
        $this->parser = new Parser();
        $installed = $this->readJson($this->root . '/vendor/composer/installed.json');
        $this->readRootAutoload(($installed['dev'] ?? false) === true);
        $this->readInstalledAutoload($installed);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function find(string $fqcn): ?ClassSource
    {
        $fqcn = ltrim($fqcn, '\\');
        $key = strtolower($fqcn);
        if (array_key_exists($key, $this->classes)) {
            return $this->classes[$key];
        }
        $this->classes[$key] = null;
        foreach ($this->candidateFiles($fqcn) as $file) {
            $this->parseFile($file);
            if ($this->classes[$key] !== null) {
                break;
            }
        }

        return $this->classes[$key];
    }

    /**
     * Bounded discovery through Composer maps. Only files containing PHP attributes are parsed.
     * @return array{classes: list<ClassSource>, scannedFiles: int, skippedFiles: int, truncated: bool}
     */
    public function attributeSources(int $maxFiles = 5000): array
    {
        $roots = array_values(array_unique([...array_merge([], ...array_values($this->psr4)),
            ...array_merge([], ...array_values($this->psr0)), ...$this->classmapPaths]));
        sort($roots);
        $seen = [];
        $sources = [];
        $skipped = 0;
        $bytes = 0;
        $visited = 0;
        $parsedBytes = 0;
        $truncated = false;
        foreach ($roots as $root) {
            $real = realpath($root);
            if ($real === false || !$this->isInsideRoot($real)) {
                ++$skipped;
                continue;
            }
            try {
                $files = is_dir($real) ? new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
                ) : [new \SplFileInfo($real)];
                foreach ($files as $file) {
                    if (++$visited > 30000 || count($seen) >= $maxFiles || $bytes > 32 * 1024 * 1024) {
                        $truncated = true;
                        break 2;
                    }
                    if (!$file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }
                    $path = $file->getRealPath();
                    if ($path === false || !$this->isInsideRoot($path)) {
                        ++$skipped;
                        continue;
                    }
                    if (isset($seen[$path])) {
                        continue;
                    }
                    $seen[$path] = true;
                    $size = $file->getSize();
                    if ($size > 1024 * 1024) {
                        ++$skipped;
                        continue;
                    }
                    if ($size > 32 * 1024 * 1024 - $bytes) {
                        $truncated = true;
                        break 2;
                    }
                    $text = @file_get_contents($path);
                    if ($text === false) {
                        ++$skipped;
                        continue;
                    }
                    $bytes += strlen($text);
                    if (!str_contains($text, '#[')) {
                        continue;
                    }
                    $parsedBytes += strlen($text);
                    if ($parsedBytes > 4 * 1024 * 1024) {
                        $truncated = true;
                        break 2;
                    }
                    $this->parseFile($path);
                    foreach ($this->classes as $class) {
                        if ($class !== null && $class->path === $this->relativePath($path)) {
                            $sources[strtolower($class->name)] = $class;
                        }
                    }
                }
            } catch (Throwable) {
                ++$skipped;
            }
        }
        ksort($sources);

        return ['classes' => array_values($sources), 'scannedFiles' => count($seen),
            'skippedFiles' => $skipped, 'truncated' => $truncated];
    }

    /**
     * Extensions every PHP build has. Classes from optional extensions (redis, pdo, intl, ...)
     * are left unresolved, so the answer does not depend on the analyzing PHP's configuration.
     */
    private const ALWAYS_PRESENT_EXTENSIONS = [
        'core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard',
    ];

    /**
     * A class or interface built into PHP itself (DateTimeImmutable, ArrayAccess, ...).
     *
     * Only already-defined internal symbols are consulted; autoloading is disabled, so no
     * project or vendor code is loaded.
     */
    public function internal(string $name): ?\ReflectionClass
    {
        $name = ltrim($name, '\\');
        if ($name === '' || (!class_exists($name, false) && !interface_exists($name, false))) {
            return null;
        }
        $reflection = new \ReflectionClass($name);
        $extension = strtolower((string) $reflection->getExtensionName());

        return $reflection->isInternal() && in_array($extension, self::ALWAYS_PRESENT_EXTENSIONS, true)
            ? $reflection
            : null;
    }

    /** Whether a missing symbol has a readable, explicit Composer namespace. */
    public function hasSourceNamespace(string $name): bool
    {
        $name = ltrim($name, '\\');
        foreach ([...$this->psr0, ...$this->psr4] as $prefix => $directories) {
            if ($prefix === '' || !str_starts_with($name, $prefix)) {
                continue;
            }
            foreach ($directories as $directory) {
                $real = realpath($directory);
                if ($real === false || !$this->isInsideRoot($real)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    public function relativePath(string $file): string
    {
        $prefix = rtrim($this->evidenceRoot ?? $this->root, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }

    public function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->evidenceRoot ?? $this->root, '/') . '/' . $path;
    }

    /** @return iterable<string> */
    private function candidateFiles(string $fqcn): iterable
    {
        foreach ($this->psr4 as $prefix => $dirs) {
            if (!str_starts_with($fqcn, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))) . '.php';
            foreach ($dirs as $dir) {
                if (is_file($dir . '/' . $relative)) {
                    yield $dir . '/' . $relative;
                }
            }
        }
        foreach ($this->psr0 as $prefix => $dirs) {
            if ($prefix !== '' && !str_starts_with($fqcn, $prefix)) {
                continue;
            }
            $relative = str_replace(['\\', '_'], '/', $fqcn) . '.php';
            foreach ($dirs as $dir) {
                if (is_file($dir . '/' . $relative)) {
                    yield $dir . '/' . $relative;
                }
            }
        }
        $file = $this->classmap()[strtolower($fqcn)] ?? null;
        if ($file !== null) {
            yield $file;
        }
    }

    private function parseFile(string $file): void
    {
        if (isset($this->parsedFiles[$file])) {
            return;
        }
        $this->parsedFiles[$file] = true;
        $real = realpath($file);
        if ($real === false || !$this->isInsideRoot($real)) {
            return;
        }
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return;
        }
        try {
            $ast = $this->parser->parseSourceFile($contents, $file);
        } catch (Throwable) {
            return;
        }
        foreach ($ast->getDescendantNodes() as $node) {
            if (
                !$node instanceof ClassDeclaration
                && !$node instanceof InterfaceDeclaration
                && !$node instanceof TraitDeclaration
                && !$node instanceof EnumDeclaration
            ) {
                continue;
            }
            $name = ltrim((string) $node->getNamespacedName(), '\\');
            if ($name === '') {
                continue;
            }
            $this->classes[strtolower($name)] = new ClassSource(
                $name,
                $node,
                $contents,
                $this->relativePath($file),
                $this->parentName($node),
                $this->interfaceNames($node),
            );
        }
    }

    private function parentName(ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $node): ?string
    {
        if (!$node instanceof ClassDeclaration || $node->classBaseClause === null) {
            return null;
        }
        $base = ParserNodes::optional($node->classBaseClause->baseClass);

        return $base instanceof QualifiedName ? $this->resolved($base) : null;
    }

    /** @return list<string> */
    private function interfaceNames(ClassDeclaration|InterfaceDeclaration|TraitDeclaration|EnumDeclaration $node): array
    {
        $list = match (true) {
            $node instanceof ClassDeclaration => ParserNodes::optional($node->classInterfaceClause),
            $node instanceof InterfaceDeclaration => ParserNodes::optional($node->interfaceBaseClause),
            $node instanceof EnumDeclaration => ParserNodes::optional($node->enumInterfaceClause),
            default => null,
        };
        $names = [];
        $elements = ParserNodes::elements(
            $list instanceof \Microsoft\PhpParser\Node ? ($list->interfaceNameList ?? null) : null,
        );
        foreach ($elements as $name) {
            if ($name instanceof QualifiedName && ($resolved = $this->resolved($name)) !== null) {
                $names[] = $resolved;
            }
        }

        return $names;
    }

    private function resolved(QualifiedName $name): ?string
    {
        $resolved = $name->getResolvedName();

        return $resolved === null ? null : ltrim((string) $resolved, '\\');
    }

    /**
     * Composer registers the root `autoload-dev` map only when dev dependencies are installed.
     */
    private function readRootAutoload(bool $dev): void
    {
        $composer = $this->readJson($this->root . '/composer.json');
        $this->addAutoload($composer['autoload'] ?? [], $this->root);
        if ($dev) {
            $this->addAutoload($composer['autoload-dev'] ?? [], $this->root);
        }
    }

    /** @param array<mixed> $installed */
    private function readInstalledAutoload(array $installed): void
    {
        $packages = $installed['packages'] ?? $installed;
        if (!is_array($packages)) {
            return;
        }
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['install-path'] ?? null)) {
                continue;
            }
            $dir = realpath($this->root . '/vendor/composer/' . $package['install-path']);
            // Path repositories may point outside the workspace; they stay outside the read boundary.
            if ($dir !== false && $this->isInsideRoot($dir)) {
                $this->addAutoload($package['autoload'] ?? [], $dir);
            }
        }
        // Longest prefix first, as Composer does.
        uksort($this->psr4, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    public function isInsideRoot(string $path): bool
    {
        $root = realpath($this->root);

        return $root !== false && ($path === $root || str_starts_with($path, rtrim($root, '/') . '/'));
    }

    private function addAutoload(mixed $autoload, string $base): void
    {
        if (!is_array($autoload)) {
            return;
        }
        foreach (['psr-4' => 'psr4', 'psr-0' => 'psr0'] as $key => $property) {
            foreach (is_array($autoload[$key] ?? null) ? $autoload[$key] : [] as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    if (is_string($prefix) && is_string($dir)) {
                        $this->{$property}[$prefix][] = rtrim($base . '/' . $dir, '/');
                    }
                }
            }
        }
        foreach (is_array($autoload['classmap'] ?? null) ? $autoload['classmap'] : [] as $path) {
            if (is_string($path)) {
                $this->classmapPaths[] = rtrim($base . '/' . $path, '/');
            }
        }
    }

    /** @return array<string, string> */
    private function classmap(): array
    {
        if ($this->classmap !== null) {
            return $this->classmap;
        }
        $this->classmap = [];
        foreach ($this->classmapPaths as $path) {
            $real = realpath($path);
            if ($real === false || !$this->isInsideRoot($real)) {
                continue;
            }
            foreach (is_dir($path) ? $this->phpFiles($path) : [$path] as $file) {
                $real = realpath($file);
                if ($real === false || !$this->isInsideRoot($real)) {
                    continue;
                }
                $contents = @file_get_contents($real);
                if ($contents === false) {
                    continue;
                }
                $namespace = preg_match('/^\s*namespace\s+([^;{\s]+)/m', $contents, $m) === 1 ? $m[1] . '\\' : '';
                if (preg_match_all(self::DECLARATION, $contents, $all) > 0) {
                    foreach ($all[1] as $class) {
                        $this->classmap[strtolower($namespace . $class)] ??= $file;
                    }
                }
            }
        }

        return $this->classmap;
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
        } catch (Throwable) {
            return;
        }
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    /** @return array<mixed> */
    private function readJson(string $file): array
    {
        $contents = @file_get_contents($file);
        $data = $contents === false ? null : json_decode($contents, true);

        return is_array($data) ? $data : [];
    }
}

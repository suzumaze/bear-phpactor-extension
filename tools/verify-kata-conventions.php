<?php

declare(strict_types=1);

/**
 * BEAR.Kata のリソースクラスが var/json_schema 規約で解決できる件数を数える。
 *
 * 使い方: php tools/verify-kata-conventions.php /private/tmp/bear-verify/kata
 *
 * リソースクラス (Resource\App / Resource\Page 名前空間で ResourceObject を
 * 継承するクラス) を psr-4 ディレクトリ配下からすべて集め、
 * JsonSchemaPathResolver::conventionPath() に通して実在するスキーマに解決
 * できた件数を数える。規約で拾えないのは「そのリソースにスキーマが無い」
 * か「クラス名に対応しない共有スキーマ (属性で名指しするもの)」なので、
 * 拾えなくて正しい。
 *
 * ★ この道具は当拡張の JsonSchemaPathResolver をそのまま呼ぶ。つまり測るのは
 * 「規約がディスク上の実在ファイルに何件届くか」であって、**正しさではない**。
 * 期待集合を独立に作る tools/references.php とは目的が違う (あちらは循環論法を
 * 避けるため拡張のクラスを1つも読み込まない)。ここでの ground truth は
 * var/json_schema に実在するファイルそのもの。
 */

require __DIR__ . '/../vendor/autoload.php';

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaPathResolver;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Parser;

$kata = $argv[1] ?? null;
if ($kata === null || !is_dir($kata)) {
    fwrite(STDERR, "usage: php tools/verify-kata-conventions.php <kata-dir>\n");
    exit(1);
}

// psr-4 を ProjectLocator::readPsr4 と同じ形 (プレフィックス末尾 '\'、ディレクトリ末尾 '/' 無し) に正規化する
$composer = json_decode((string) file_get_contents($kata . '/composer.json'), true);
$psr4 = [];
foreach (['autoload', 'autoload-dev'] as $section) {
    foreach (($composer[$section]['psr-4'] ?? []) as $prefix => $dirs) {
        foreach ((array) $dirs as $dir) {
            $psr4[rtrim((string) $prefix, '\\') . '\\'][] = rtrim((string) $dir, '/');
        }
    }
}

$parser = new Parser();
$resolver = new JsonSchemaPathResolver();

$files = [];
foreach ($psr4 as $dirs) {
    foreach ($dirs as $dir) {
        $base = str_starts_with($dir, '/') ? $dir : $kata . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
}
sort($files);

$resolved = [];
$unresolved = [];
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    $rootNode = $parser->parseSourceFile($source);
    foreach ($rootNode->getDescendantNodes() as $node) {
        if (!$node instanceof ClassDeclaration) {
            continue;
        }
        $base = $node->classBaseClause?->baseClass;
        if (!$base instanceof QualifiedName) {
            continue;
        }
        $baseText = ltrim($base->getText(), '\\');
        if ($baseText !== 'ResourceObject' && $baseText !== 'BEAR\Resource\ResourceObject') {
            continue;
        }
        $namespace = $node->getNamespaceDefinition();
        if (!$namespace instanceof NamespaceDefinition || !$namespace->name instanceof QualifiedName) {
            continue;
        }
        $name = $node->name;
        if ($name === null) {
            continue;
        }
        $namespaceText = $namespace->name->getText();
        $className = $name->getText($source);
        $path = $resolver->conventionPath($kata, $psr4, $file, $namespaceText, $className);
        $label = $className . ' @ ' . str_replace($kata . '/', '', $file);
        if ($path !== null) {
            $resolved[$label] = str_replace($kata . '/', '', $path);
        } else {
            $unresolved[] = $label;
        }
    }
}

printf("resolved: %d\n", count($resolved));
foreach ($resolved as $class => $schema) {
    printf("  %-64s -> %s\n", $class, $schema);
}
printf("unresolved: %d\n", count($unresolved));
foreach ($unresolved as $class) {
    printf("  %s\n", $class);
}

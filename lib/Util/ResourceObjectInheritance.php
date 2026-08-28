<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Util;

use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Parser;

/**
 * クラスが BEAR\Resource\ResourceObject を (間接的に) 継承しているかの判定。
 *
 * 継承の連鎖を、プロジェクトの psr-4 対応表 (composer.json の「名前空間 →
 * ディレクトリ」の対応) を使って自分で辿る。phpactor の Reflector (クラス階層
 * を解決する仕組み) は使わない。参照検索 (ResourceReferenceFinder) と URI補完
 * (Project::resourceClasses()) の2経路が同じ判定を共有するための共通部品。
 *
 * 既知の限界:
 * - 親クラスはディスクから読む。エディタの未保存の編集は、判定の起点となる
 *   ドキュメントには反映されるが、親クラス側には反映されない (PLAN.md §2.11
 *   と同じ種類の限界)。
 * - vendor にある基底クラスは辿れない。psr-4 対応表はアプリ自身のコードを指す
 *   もので、vendor のクラスはファイルに解決できない。BEAR\Resource\ResourceObject
 *   自身は直接比較で拾うため、これだけは問題にならない。
 */
final class ResourceObjectInheritance
{
    /** 継承の連鎖を辿る深さの上限。壊れたコードで止まらなくならないための安全弁 */
    private const MAX_DEPTH = 20;

    /** @var array<string, bool> 完全修飾名 → リソースか (メモ化) */
    private array $memo = [];

    /**
     * @param array<string, list<string>> $psr4 psr-4プレフィックス → ディレクトリ一覧
     */
    public function __construct(
        private string $root,
        private array $psr4,
        private Parser $parser = new Parser(),
    ) {
    }

    /**
     * クラス宣言が BEAR\Resource\ResourceObject を (間接的に) 継承しているか。
     */
    public function extendsResourceObject(ClassDeclaration $class): bool
    {
        if ($class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
            return false;
        }
        $resolved = $class->classBaseClause->baseClass->getResolvedName();
        if ($resolved === null) {
            return false;
        }

        return $this->isResourceFqn((string) $resolved, [], 0);
    }

    /**
     * 完全修飾名が BEAR\Resource\ResourceObject に (間接的に) 行き着くか。
     *
     * @param list<string> $chain 現在の連鎖 (循環の検出用)
     */
    private function isResourceFqn(string $fqn, array $chain, int $depth): bool
    {
        if ($fqn === 'BEAR\Resource\ResourceObject') {
            return true;
        }
        if (isset($this->memo[$fqn])) {
            return $this->memo[$fqn];
        }
        if ($depth >= self::MAX_DEPTH) {
            return false;
        }
        if (in_array($fqn, $chain, true)) {
            // 循環 (A extends B かつ B extends A)。編集途中の壊れたコードは
            // エディタの中に日常的に存在する。止まらないと参照検索が固まる
            // (このリポジトリで25分ハングした過去がある)。
            return false;
        }

        $file = $this->fileForFqn($fqn);
        if ($file === null) {
            return false;
        }
        $class = PhpClassDeclaration::find($file, $this->parser);
        if ($class === null || $class->classBaseClause === null || $class->classBaseClause->baseClass === null) {
            return false;
        }
        $parent = $class->classBaseClause->baseClass->getResolvedName();
        if ($parent === null) {
            return false;
        }
        $result = $this->isResourceFqn((string) $parent, [...$chain, $fqn], $depth + 1);
        $this->memo[$fqn] = $result;

        return $result;
    }

    /**
     * 完全修飾名を psr-4 対応表でファイルパスに変換する。解決できなければ null。
     */
    private function fileForFqn(string $fqn): ?string
    {
        // PSR-4 は最も深く一致するプレフィックスを採る。
        $bestPrefix = null;
        $bestLen = -1;
        foreach ($this->psr4 as $prefix => $dirs) {
            if (!str_starts_with($fqn, $prefix) || strlen($prefix) <= $bestLen) {
                continue;
            }
            $bestLen = strlen($prefix);
            $bestPrefix = $prefix;
        }
        if ($bestPrefix === null) {
            return null;
        }

        $relative = substr($fqn, strlen($bestPrefix));
        foreach ($this->psr4[$bestPrefix] as $dir) {
            $base = PathGuard::isAbsolutePath($dir) ? $dir : $this->root . '/' . $dir;
            $file = $base . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}

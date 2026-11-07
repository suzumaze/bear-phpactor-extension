<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\WorseReflection;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\AssignmentExpression;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\TokenKind;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Core\Type\AggregateType;
use Phpactor\WorseReflection\Core\Type\ArrayType;
use Phpactor\WorseReflection\Core\Type\ClassType;
use Phpactor\WorseReflection\Core\Type\IterableType;
use Phpactor\WorseReflection\Core\Type\NullableType;
use Phpactor\WorseReflection\Core\Type\NullType;
use Phpactor\WorseReflection\Core\Type\ScalarType;
use Phpactor\WorseReflection\Reflector;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceBodyTypeResolver;
use Throwable;

/**
 * body に書き込む値の型を、Phpactor (WorseReflection) の型推論で求める。
 *
 * 値の式を保存済みファイルから構文解析し直し、公開 API の Reflector::reflectNodeContext()
 * に渡す (その位置までの変数の状態を作ってから式を推論する)。`kind` は外側の型の大まかな
 * 分類、`classes` は配列の要素型・null 許容・union・ジェネリクスの中まで降りて集めた
 * クラス名で、「body のどこかにオブジェクトが入るか」に答える。推論できない値や例外は
 * `unknown` にして、describe 全体を失敗させない。
 *
 * 値の位置にある代入 (`'id' => $id = $this->post($x)`) は、Phpactor が代入式そのものに型を
 * 付けないため、右辺を推論する (body に入るのは右辺の値)。複合代入 (`+=` など) は右辺が
 * 入る値ではないので、そのまま渡す。
 */
final class WorseReflectionBodyTypeResolver implements ResourceBodyTypeResolver
{
    public function __construct(
        private Reflector $reflector,
    ) {
    }

    public function resolve(string $path, array $valueRanges): array
    {
        $source = is_file($path) ? file_get_contents($path) : false;
        $nodes = $source === false ? [] : $this->nodesAt((new Parser())->parseSourceFile($source), $valueRanges);

        $items = [];
        foreach ($valueRanges as $name => $range) {
            $type = null;
            if (isset($nodes[$name])) {
                try {
                    $type = $this->reflector->reflectNodeContext($this->assignedValue($nodes[$name]))->type();
                } catch (Throwable) {
                    $type = null;
                }
            }
            $items[] = $type === null
                ? ['name' => $name, 'type' => '<missing>', 'kind' => self::KIND_UNKNOWN, 'classes' => []]
                : [
                    'name' => $name,
                    'type' => (string) $type,
                    'kind' => $this->kind($type),
                    'classes' => $this->classes($type),
                ];
        }

        return $items;
    }

    /**
     * @param array<string, array{int, int}> $valueRanges
     * @return array<string, Node> the outermost node that spans each range exactly
     */
    private function nodesAt(Node $root, array $valueRanges): array
    {
        $names = [];
        foreach ($valueRanges as $name => [$start, $end]) {
            $names[$start . ':' . $end][] = $name;
        }
        $nodes = [];
        foreach ($root->getDescendantNodes() as $node) {
            $key = $node->getStartPosition() . ':' . $node->getEndPosition();
            foreach ($names[$key] ?? [] as $name) {
                $nodes[$name] ??= $node;
            }
        }

        return $nodes;
    }

    /** The value a plain assignment (`$a = $b = expr`) yields is its innermost right operand. */
    private function assignedValue(Node $node): Node
    {
        while (
            $node instanceof AssignmentExpression
            && $node->operator->kind === TokenKind::EqualsToken
        ) {
            $node = $node->rightOperand;
        }

        return $node;
    }

    private function kind(Type $type): string
    {
        $kinds = array_values(array_unique($this->kinds($type)));
        if (in_array(self::KIND_UNKNOWN, $kinds, true)) {
            return self::KIND_UNKNOWN;
        }

        return match (count($kinds)) {
            0 => self::KIND_NULL,
            1 => $kinds[0],
            default => self::KIND_UNION,
        };
    }

    /** @return list<string> the kinds of the members, null left out */
    private function kinds(Type $type): array
    {
        if ($type instanceof NullableType) {
            return $this->kinds($type->type);
        }
        if ($type instanceof AggregateType) {
            $kinds = [];
            foreach ($type->expandTypes() as $member) {
                $kinds = [...$kinds, ...$this->kinds($member)];
            }

            return $kinds;
        }

        return match (true) {
            $type instanceof NullType => [],
            $type instanceof ClassType => [self::KIND_OBJECT],
            $type instanceof ArrayType => [self::KIND_ARRAY],
            // `iterable` and other non-array iterables: a collection that may be an array or a Traversable.
            $type instanceof IterableType => [self::KIND_ITERABLE],
            $type instanceof ScalarType => [self::KIND_SCALAR],
            default => [self::KIND_UNKNOWN],
        };
    }

    /** @return list<string> */
    private function classes(Type $type): array
    {
        $classes = [];
        foreach ($type->allTypes() as $member) {
            if ($member instanceof ClassType) {
                $classes[] = $member->name()->full();
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }
}

<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di;

use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\Expression\ArgumentExpression;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\StaticBindingValue;
use Suzumaze\BearPhpactor\Semantic\Di\RayModuleCall;

final class StaticBindingValueTest extends TestCase
{
    /** @return iterable<string,array{string,string|null}> */
    public static function valueTypes(): iterable
    {
        yield 'single-quoted string' => ["'dsn'", 'string'];
        yield 'interpolated string' => ['"db_$name"', 'string'];
        yield 'nowdoc' => ["<<<'SQL'\nSELECT 1\nSQL", 'string'];
        yield 'class constant name' => ['Clock::class', 'string'];
        yield 'integer' => ['30', 'integer'];
        yield 'negative integer' => ['-1', 'integer'];
        yield 'hex integer' => ['0x1E', 'integer'];
        yield 'float' => ['1.5', 'double'];
        yield 'exponent float' => ['1e3', 'double'];
        yield 'true' => ['true', 'boolean'];
        yield 'uppercase false' => ['FALSE', 'boolean'];
        yield 'null' => ['null', 'NULL'];
        yield 'short array' => ["['a' => 1]", 'array'];
        yield 'long array' => ['array(1)', 'array'];
        yield 'parenthesized' => ["('x')", 'string'];
        yield 'string cast' => ['(string) $port', 'string'];
        yield 'int cast' => ['(int) $port', 'integer'];
        yield 'integer cast' => ['(integer) $port', 'integer'];
        yield 'bool cast' => ['(bool) $debug', 'boolean'];
        yield 'boolean cast' => ['(boolean) $debug', 'boolean'];
        yield 'float cast' => ['(float) $ratio', 'double'];
        yield 'double cast' => ['(double) $ratio', 'double'];
        yield 'array cast' => ['(array) $hosts', 'array'];
        yield 'object cast' => ['(object) $hosts', 'object'];
        yield 'new object' => ['new Clock()', 'object'];
        yield 'variable' => ['$value', null];
        yield 'function call' => ["getenv('DSN')", null];
        yield 'class constant' => ['Config::DSN', null];
        yield 'concatenation' => ["'a' . 'b'", null];
    }

    #[DataProvider('valueTypes')]
    public function testInfersGettypeNameFromExpressionFormOnly(string $expression, ?string $expected): void
    {
        [$node, $source] = $this->argument($expression);

        self::assertSame($expected, StaticBindingValue::valueType($node, $source));
    }

    /** @return iterable<string,array{string,string|null}> */
    public static function scopes(): iterable
    {
        yield 'singleton constant' => ['Scope::SINGLETON', 'Singleton'];
        yield 'prototype constant' => ['Scope::PROTOTYPE', 'Prototype'];
        yield 'fully qualified constant' => ['\\Ray\\Di\\Scope::SINGLETON', 'Singleton'];
        yield 'literal' => ["'Prototype'", 'Prototype'];
        yield 'unknown literal is returned raw' => ["'singleton'", 'singleton'];
        yield 'same short name in another namespace' => ['OtherScope::SINGLETON', null];
        yield 'unknown Ray.Di constant' => ['Scope::REQUEST', null];
        yield 'variable' => ['$scope', null];
    }

    #[DataProvider('scopes')]
    public function testReadsRayDiScopeFromLiteralOrResolvedConstant(string $expression, ?string $expected): void
    {
        [$node, $source] = $this->argument($expression);

        self::assertSame($expected, StaticBindingValue::scope($node, $source));
    }

    public function testSourceLiteralEscapesAreDecodedLikePhpValues(): void
    {
        [$node, $source] = $this->argument("'Fx\\\\Svc\\\\IX'");
        self::assertSame('Fx\\Svc\\IX', RayModuleCall::staticName($node, $source));

        [$scope, $scopeSource] = $this->argument("'Single\\'ton'");
        self::assertSame("Single'ton", StaticBindingValue::scope($scope, $scopeSource));
    }

    /** @return array{Node|null,string} */
    private function argument(string $expression): array
    {
        $source = "<?php\nuse Ray\\Di\\Scope;\nuse Acme\\OtherScope;\nf({$expression});\n";
        foreach ((new Parser())->parseSourceFile($source)->getDescendantNodes() as $node) {
            if (!$node instanceof CallExpression || $node->argumentExpressionList === null) {
                continue;
            }
            $argument = $node->argumentExpressionList->getElements()->current();
            self::assertInstanceOf(ArgumentExpression::class, $argument);

            return [$argument->expression, $source];
        }
        self::fail('No call expression in ' . $source);
    }
}

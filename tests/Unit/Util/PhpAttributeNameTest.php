<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Util;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Util\PhpAttributeName;

final class PhpAttributeNameTest extends TestCase
{
    /** @return iterable<string,array{string,bool}> */
    public static function names(): iterable
    {
        yield 'import alias' => [
            '<?php namespace App; use BEAR\Resource\Annotation\Embed as Relation; #[Relation] class Example {}',
            true,
        ];
        yield 'fully qualified' => [
            '<?php namespace App; #[\BEAR\Resource\Annotation\Embed] class Example {}',
            true,
        ];
        yield 'global namespace qualified name' => [
            '<?php #[BEAR\Resource\Annotation\Embed] class Example {}',
            true,
        ];
        yield 'namespace relative written fqn' => [
            '<?php namespace App; #[BEAR\Resource\Annotation\Embed] class Example {}',
            false,
        ];
        yield 'unrelated same short name' => [
            '<?php namespace App; use Acme\Other\Embed; #[Embed] class Example {}',
            false,
        ];
    }

    #[DataProvider('names')]
    public function testMatchesResolvedPhpAttributeName(string $source, bool $expected): void
    {
        $attribute = null;
        foreach ((new Parser())->parseSourceFile($source)->getDescendantNodes() as $node) {
            if ($node instanceof Attribute) {
                $attribute = $node;
                break;
            }
        }
        self::assertInstanceOf(Attribute::class, $attribute);

        self::assertSame(
            $expected,
            PhpAttributeName::is($attribute, 'BEAR\Resource\Annotation\Embed'),
        );
    }

    public function testLegacyModeAcceptsWrittenFqnInsideNamespace(): void
    {
        $source = '<?php namespace App; #[BEAR\Resource\Annotation\Embed] class Example {}';
        $attribute = null;
        foreach ((new Parser())->parseSourceFile($source)->getDescendantNodes() as $node) {
            if ($node instanceof Attribute) {
                $attribute = $node;
                break;
            }
        }
        self::assertInstanceOf(Attribute::class, $attribute);

        self::assertTrue(PhpAttributeName::is(
            $attribute,
            'BEAR\Resource\Annotation\Embed',
            acceptLegacyWrittenFqn: true,
        ));
    }
}

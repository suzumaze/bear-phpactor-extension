<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Di\Composition;

use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\PhpStringLiteral;

final class PhpStringLiteralTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function literals(): iterable
    {
        yield 'unknown escapes' => ['"Acme\Service"', 'Acme\Service'];
        yield 'double escaped namespace' => ['"Acme\\\\Service"', 'Acme\Service'];
        yield 'single quoted namespace' => ["'Acme\\Service'", 'Acme\Service'];
        yield 'escaped closing quote' => ['"text' . chr(92) . '""', 'text"'];
        yield 'single quote' => ["'text" . chr(92) . "''", "text'"];
        yield 'control escapes' => ['"\\n\\r\\t\\v\\e\\f"', "\n\r\t\v\e\f"];
        yield 'octal hex unicode' => ['"\\101\\x42\\u{43}\\u{1F600}"', "ABC\u{1F600}"];
        yield 'escaped dollar' => ['"\\$name"', '$name'];
        yield 'nowdoc' => ["<<<'TEXT'\nAcme\\Service\\n\nTEXT", 'Acme\Service\n'];
        yield 'heredoc' => ["<<<TEXT\nAcme\\Service\\n\nTEXT", "Acme\\Service\n"];
        yield 'indented nowdoc' => ["<<<'TEXT'\n    Acme\\Service\n    TEXT", 'Acme\Service'];
    }

    #[DataProvider('literals')]
    public function testPhpStringSemantics(string $literal, string $expected): void
    {
        $ast = (new Parser())->parseSourceFile('<?php $value = ' . $literal . ';');
        $node = $ast->getFirstDescendantNode(StringLiteral::class);
        self::assertInstanceOf(StringLiteral::class, $node);
        self::assertSame($expected, PhpStringLiteral::decode($node));
    }
}

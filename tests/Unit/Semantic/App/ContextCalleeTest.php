<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\App;

use Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\App\ContextCallee;

#[CoversClass(ContextCallee::class)]
final class ContextCalleeTest extends TestCase
{
    public function testEvaluatesPathsBuiltFromLiteralsAndDirectoryConstants(): void
    {
        self::assertSame(
            '/app/bootstrap/x.php',
            $this->path("dirname(__DIR__) . '/bootstrap/x.php'", '/app/bin/a.php'),
        );
        self::assertSame('/app/x.php', $this->path("dirname(__DIR__, 2) . '/x.php'", '/app/bin/sub/a.php'));
        self::assertSame('/app/bin/a.php', $this->path('__FILE__', '/app/bin/a.php'));
        self::assertNull($this->path("__DIR__ . '/' . \$name", '/app/bin/a.php'));
        self::assertNull($this->path("dirname(__DIR__, \$levels) . '/x.php'", '/app/bin/a.php'));
    }

    public function testGivesUpOnPathsWithManyPiecesInsteadOfRecursingWithoutBound(): void
    {
        $short = implode(' . ', array_fill(0, 10, "'a'"));
        $long = implode(' . ', array_fill(0, 40, "'a'"));

        self::assertSame(str_repeat('a', 10), $this->path($short, '/app/bin/a.php'));
        self::assertNull($this->path($long, '/app/bin/a.php'));
    }

    private function path(string $expression, string $file): ?string
    {
        $parsed = (new Parser())->parseSourceFile('<?php require ' . $expression . ';');
        foreach ($parsed->getDescendantNodes() as $node) {
            if ($node instanceof ScriptInclusionExpression) {
                return ContextCallee::path($node->expression, $file);
            }
        }
        self::fail('No require expression found.');
    }
}

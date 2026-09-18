<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Result;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Semantic\Result\Freshness;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticError;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

final class SemanticResultTest extends TestCase
{
    public function testSuccessCarriesSortedDeduplicatedProvenance(): void
    {
        $result = SemanticResult::ok('value', [
            Provenance::savedFile('src/Z.php'),
            Provenance::savedFile('src/A.php', 4, 8),
            Provenance::savedFile('src/Z.php'),
        ]);

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertNull($result->error);
        self::assertSame(['src/A.php', 'src/Z.php'], array_column($result->provenance, 'path'));
        self::assertSame([Freshness::Saved, Freshness::Saved], array_column($result->provenance, 'freshness'));
    }

    public function testAddingProvenancePreservesFailureAndError(): void
    {
        $error = new SemanticError('resource_target_missing', 'The Resource does not exist.');
        $result = SemanticResult::failure(SemanticStatus::NotFound, $error)
            ->withProvenance([Provenance::savedFile('composer.json')]);

        self::assertSame(SemanticStatus::NotFound, $result->status);
        self::assertSame($error, $result->error);
        self::assertSame(['composer.json'], array_column($result->provenance, 'path'));
    }

    public function testNotFoundCanCarryAnExplanatoryPartialValue(): void
    {
        $result = SemanticResult::notFoundWithPartial(
            ['resolved' => 'resource'],
            provenance: [Provenance::savedFile('src/Resource/App/User.php')],
        );

        self::assertSame(SemanticStatus::NotFound, $result->status);
        self::assertNull($result->value);
        self::assertSame(['resolved' => 'resource'], $result->partial);
        self::assertSame('semantic_not_found', $result->error?->code);
        self::assertSame(['src/Resource/App/User.php'], array_column($result->provenance, 'path'));
    }

    public function testPartialNotFoundRequiresAValue(): void
    {
        $this->expectException(LogicException::class);

        SemanticResult::notFoundWithPartial(null);
    }

    /** @return iterable<string,array{SemanticStatus,string}> */
    public static function failureCodes(): iterable
    {
        yield 'not found' => [SemanticStatus::NotFound, 'semantic_not_found'];
        yield 'ambiguous' => [SemanticStatus::Ambiguous, 'semantic_ambiguous'];
        yield 'invalid input' => [SemanticStatus::InvalidInput, 'semantic_invalid_input'];
        yield 'unsupported' => [SemanticStatus::Unsupported, 'semantic_unsupported'];
        yield 'parse error' => [SemanticStatus::ParseError, 'semantic_parse_error'];
        yield 'engine unavailable' => [SemanticStatus::EngineUnavailable, 'semantic_engine_unavailable'];
        yield 'outside workspace' => [SemanticStatus::OutsideWorkspace, 'semantic_outside_workspace'];
        yield 'timeout' => [SemanticStatus::Timeout, 'semantic_timeout'];
    }

    #[DataProvider('failureCodes')]
    public function testEveryFailureStatusHasStableErrorCode(SemanticStatus $status, string $code): void
    {
        $result = $status === SemanticStatus::Ambiguous
            ? SemanticResult::ambiguous(['a', 'b'])
            : SemanticResult::failure($status);

        self::assertSame($code, $result->error?->code);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'absolute' => ['/tmp/outside.php'];
        yield 'windows absolute' => ['C:/outside.php'];
        yield 'parent' => ['../outside.php'];
        yield 'current segment' => ['src/./File.php'];
        yield 'empty segment' => ['src//File.php'];
    }

    #[DataProvider('unsafePaths')]
    public function testFileProvenanceRejectsUnsafePaths(string $path): void
    {
        $this->expectException(LogicException::class);

        Provenance::savedFile($path);
    }

    public function testDerivedProvenanceDoesNotInventAPath(): void
    {
        $derived = Provenance::derived();

        self::assertSame(Provenance::SOURCE_DERIVED, $derived->source);
        self::assertNull($derived->path);
        self::assertSame(Freshness::Saved, $derived->freshness);
    }
}

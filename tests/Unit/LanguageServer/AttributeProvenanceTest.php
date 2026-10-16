<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\LanguageServer;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;

use function Amp\Promise\wait;

final class AttributeProvenanceTest extends TestCase
{
    public function testSummaryPreservesFactsAndEvidenceCounts(): void
    {
        $handler = new SemanticQueryHandler(dirname(__DIR__, 2) . '/Fixture/AttributeSummary');
        $full = wait($handler->indexResourceAttributes(limit: 2));
        $summary = wait($handler->indexResourceAttributes(limit: 2, provenanceDetail: 'summary'));
        self::assertSame('ok', $summary['status']);
        self::assertSame($full['data'], $summary['data']);
        self::assertTrue($summary['data']['truncated']);
        self::assertSame([], $summary['provenance']);
        $evidence = $summary['coverage']['provenance'];
        self::assertSame(count($full['provenance']), $evidence['omittedEntries']);
        self::assertSame(count(array_unique(array_column($full['provenance'], 'path'))), $evidence['fileCount']);
        self::assertSame(['saved'], $evidence['freshness']);
        self::assertSame('response_evidence', $evidence['scope']);
        self::assertSame($full, wait($handler->indexResourceAttributes(limit: 2, provenanceDetail: 'full')));
        $invalid = wait($handler->indexResourceAttributes(provenanceDetail: 'unknown'));
        self::assertSame('invalid_input', $invalid['status']);
    }
}

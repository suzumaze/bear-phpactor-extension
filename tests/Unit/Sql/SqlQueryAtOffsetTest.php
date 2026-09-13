<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Sql;

use Phpactor\TextDocument\TextDocumentBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\Sql\SqlQueryAtOffset;

final class SqlQueryAtOffsetTest extends TestCase
{
    public function testRecognizesImportedAttributeAndLegacyDocblock(): void
    {
        $source = <<<'PHP'
<?php
use Ray\MediaQuery\Annotation\DbQuery as MediaQuery;
#[MediaQuery('find_user', type: 'row')]
/** @Query("legacy_user") */
interface Query {}
PHP;
        $document = TextDocumentBuilder::create($source)->language('php')->build();
        $detector = new SqlQueryAtOffset();

        $attributeStart = (int) strpos($source, 'find_user');
        self::assertSame(
            [$attributeStart, 'find_user', $attributeStart + strlen('find_user')],
            $detector($document, $attributeStart),
        );
        $annotationStart = (int) strpos($source, 'legacy_user');
        self::assertSame(
            [$annotationStart, 'legacy_user', $annotationStart + strlen('legacy_user')],
            $detector($document, $annotationStart),
        );
    }

    public function testRejectsForeignAttributeLaterArgumentAndQuoteBoundary(): void
    {
        $foreignSource = <<<'PHP'
<?php
use Other\DbQuery;
#[DbQuery('foreign', type: 'row')]
interface Query {}
PHP;
        $detector = new SqlQueryAtOffset();
        $foreignDocument = TextDocumentBuilder::create($foreignSource)->language('php')->build();
        self::assertNull($detector($foreignDocument, (int) strpos($foreignSource, 'foreign')));

        $validSource = <<<'PHP'
<?php
#[\Ray\MediaQuery\Annotation\DbQuery('find_user', type: 'row')]
interface Query {}
PHP;
        $validDocument = TextDocumentBuilder::create($validSource)->language('php')->build();
        self::assertNull($detector($validDocument, (int) strpos($validSource, 'row')));
        self::assertNull($detector($validDocument, (int) strpos($validSource, "'find_user'")));
        $closingBoundary = (int) strpos($validSource, "'find_user'") + strlen("'find_user'");
        self::assertNull($detector($validDocument, $closingBoundary));
    }

    public function testRecognizesExplicitFqnAndNamedIdButRejectsNamedType(): void
    {
        $source = <<<'PHP'
<?php
#[\Ray\MediaQuery\Annotation\DbQuery(id: 'named_id')]
interface NamedQuery {}
#[\Ray\MediaQuery\Annotation\DbQuery(type: 'not_an_id')]
interface InvalidQuery {}
PHP;
        $document = TextDocumentBuilder::create($source)->language('php')->build();
        $detector = new SqlQueryAtOffset();

        $namedStart = (int) strpos($source, 'named_id');
        self::assertSame(
            [$namedStart, 'named_id', $namedStart + strlen('named_id')],
            $detector($document, $namedStart),
        );
        self::assertNull($detector($document, (int) strpos($source, 'not_an_id')));
    }

    public function testRejectsDynamicAttributeAndNonDocCommentText(): void
    {
        $source = <<<'PHP'
<?php
use Ray\MediaQuery\Annotation\DbQuery;
#[DbQuery(self::QUERY_ID)]
// @Query("comment_query")
$value = '@Query("string_query")';
PHP;
        $document = TextDocumentBuilder::create($source)->language('php')->build();
        $detector = new SqlQueryAtOffset();

        self::assertNull($detector($document, (int) strpos($source, 'QUERY_ID')));
        self::assertNull($detector($document, (int) strpos($source, 'comment_query')));
        self::assertNull($detector($document, (int) strpos($source, 'string_query')));
    }
}

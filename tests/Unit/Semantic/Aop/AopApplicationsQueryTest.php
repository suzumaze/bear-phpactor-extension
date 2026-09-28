<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Aop;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Aop\SourceMatcher;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ClassSourceIndex;

use function Amp\Promise\wait;

final class AopApplicationsQueryTest extends TestCase
{
    public function testPriorityAnnotationOrderReplacementAndDuplicates(): void
    {
        $result = wait($this->handler()->listAopApplications('advice-app', 'app://self/advice'));
        self::assertSame('ok', $result['status']);
        self::assertSame(2, $result['data']['total']);
        $item = $result['data']['items'][0];
        self::assertSame('onGet', $item['method']);
        self::assertNotContains('helper', array_column($result['data']['items'], 'method'));
        self::assertSame('default_public_on_methods', $result['data']['coverage']['methodScope']);
        self::assertSame(array_map(static fn ($name) => 'Acme\\Shop\\Interceptor\\' . $name, [
            'Priority', 'Second', 'First', 'Tail', 'Tail',
        ]), array_column($item['chain'], 'interceptor'));
        self::assertSame('src/Module/AdviceModule.php', $item['chain'][0]['origin']['path']);
        self::assertFalse($result['data']['coverage']['runtimeObserved']);
        // The deliberately opaque AssistedInject matcher in the fixture must not silently disappear.
        self::assertSame('provisional', $item['status']);
        self::assertNotEmpty($item['unresolvedPointcuts']);
        $filtered = wait($this->handler()->listAopApplications(
            'advice-app',
            'app://self/advice',
            'Acme\\Shop\\Interceptor\\Old',
        ));
        self::assertSame(0, $filtered['data']['total']);
        self::assertGreaterThan(0, $filtered['data']['unknownTotal']);
        self::assertGreaterThan(0, $filtered['data']['unresolvedPointcutTotal']);
    }

    public function testResourceReplacementUsesSelectedClass(): void
    {
        $result = wait($this->handler()->listAopApplications('replacement-advice-app', 'app://self/advice'));
        foreach ($result['data']['items'] as $item) {
            self::assertSame('Acme\\Shop\\Resource\\App\\Replacement', $item['target']);
            self::assertSame(['final_class'], $item['weavingBlockers']);
        }
        self::assertNotEmpty($result['data']['items']);
    }

    public function testMissingContextRejectedAndPaginationAdvances(): void
    {
        self::assertSame('invalid_input', wait($this->handler()->listAopApplications(''))['status']);
        $result = wait($this->handler()->listAopApplications('advice-app', limit: 1));
        self::assertCount(1, $result['data']['items']);
        self::assertTrue($result['data']['truncated']);
        $nextPage = wait($this->handler()->listAopApplications(
            'advice-app',
            limit: 1,
            offset: 1,
        ));
        self::assertNotSame($result['data']['items'], $nextPage['data']['items']);
        self::assertSame($result['data']['unknownSummary'], $nextPage['data']['unknownSummary']);
    }

    public function testUnknownHierarchyDoesNotBecomeNegative(): void
    {
        $matcher = new SourceMatcher(new ClassSourceIndex($this->root()));
        self::assertNull($matcher->isA('Missing\\Attribute', 'Acme\\Shop\\Annotation\\First'));
        self::assertFalse($matcher->isA('Acme\\Shop\\Annotation\\Second', 'Acme\\Shop\\Annotation\\First'));
    }

    public function testInternalHierarchyIsCompleteWithoutLoadingApplicationClasses(): void
    {
        $matcher = new SourceMatcher(new ClassSourceIndex($this->root()));
        $autoloadAttempted = false;
        $loader = static function (string $class) use (&$autoloadAttempted): void {
            if ($class === 'Acme\\Shop\\Annotation\\First') {
                $autoloadAttempted = true;
            }
        };
        spl_autoload_register($loader);
        try {
            self::assertTrue($matcher->isA('ArrayObject', 'Traversable'));
            self::assertFalse($matcher->isA('JsonSerializable', 'Acme\\Shop\\Annotation\\First'));
            self::assertNull($matcher->isA('Missing\\Attribute', 'Acme\\Shop\\Annotation\\First'));
        } finally {
            spl_autoload_unregister($loader);
        }

        self::assertFalse($autoloadAttempted);
    }

    public function testAnnotationReplacementHappensBeforeClassConditionMatching(): void
    {
        $result = wait($this->handler()->listAopApplications('advice-nonmatch-app', 'app://self/advice'));
        $chain = array_column($result['data']['items'][0]['chain'], 'interceptor');
        self::assertNotContains('Acme\\Shop\\Interceptor\\First', $chain);
        self::assertNotContains('Acme\\Shop\\Interceptor\\Old', $chain);
        self::assertContains('Acme\\Shop\\Interceptor\\Second', $chain);
    }

    public function testProviderReplacementIsUnresolvedInsteadOfMatchingDeclaredResource(): void
    {
        $result = wait($this->handler()->listAopApplications('indirect-advice-app', 'app://self/advice'));
        self::assertSame([], $result['data']['items']);
        self::assertContains('resource_binding_not_class', array_column($result['data']['unknowns'], 'reason'));
    }

    public function testAttributeFilterSelectsMethodsWithMatchingConditions(): void
    {
        $result = wait($this->handler()->listAopApplications(
            'advice-app',
            'app://self/advice',
            attribute: 'Acme\\Shop\\Annotation\\First',
        ));
        self::assertSame(1, $result['data']['total']);
        self::assertSame('onGet', $result['data']['items'][0]['method']);
        $summary = $result['data']['unknownSummary'];
        self::assertSame(
            $result['data']['unknownTotal'],
            $summary['compositionOccurrences'] + $summary['resourceOccurrences'] + $summary['applicationOccurrences'],
        );
        self::assertSame(
            $result['data']['unresolvedPointcutTotal'],
            $summary['unresolvedPointcutRegistrations'] + $summary['applicationOccurrences'],
        );
        self::assertSame(1, $summary['filterMatchedMethods']);
        self::assertSame($result['data']['total'], $summary['filterMatchedMethods']);
        self::assertArrayHasKey('truncated', $summary['groups']);
    }

    public function testExactMethodFilterIncludesPublicHelperOutsideDefaultScope(): void
    {
        $default = wait($this->handler()->listAopApplications('advice-app', 'app://self/advice'));
        self::assertNotContains('helper', array_column($default['data']['items'], 'method'));

        $selected = wait($this->handler()->listAopApplications(
            'advice-app',
            'app://self/advice',
            method: 'helper',
        ));
        self::assertSame(1, $selected['data']['total']);
        self::assertSame('helper', $selected['data']['items'][0]['method']);
        self::assertSame('exact_public_method', $selected['data']['coverage']['methodScope']);
    }

    private function handler(): SemanticQueryHandler
    {
        return new SemanticQueryHandler($this->root());
    }

    private function root(): string
    {
        return dirname(__DIR__, 3) . '/Fixture/DiComposition';
    }
}

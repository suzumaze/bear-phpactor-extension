<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceTargetResolver;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceQuery;
use Suzumaze\BearPhpactor\Semantic\Resource\ResourceResolution;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use PHPUnit\Framework\TestCase;

final class ResourceQueryTest extends TestCase
{
    private ResourceQuery $query;
    private Project $project;

    protected function setUp(): void
    {
        $this->query = new ResourceQuery();
        $project = Project::locate(self::fixtureDir() . '/src/Client.php');
        self::assertNotNull($project);
        $this->project = $project;
    }

    public function testResolvesDirectSelfResource(): void
    {
        $result = $this->query->resolveString($this->project, 'app://self/user');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceResolution::class, $result->value);
        self::assertSame('app://self/user', $result->value->uri->uri());
        self::assertSame('Acme\Blog\Resource\App\User', $result->value->fqn);
        self::assertSame(self::fixtureDir() . '/src/Resource/App/User.php', $result->value->file);
        self::assertSame([], $result->candidates);
    }

    public function testResolvesImportedResource(): void
    {
        $result = $this->query->resolveString($this->project, 'app://tags/api/search');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceResolution::class, $result->value);
        self::assertSame('Acme\Tags\Resource\App\Api\Search', $result->value->fqn);
        self::assertSame(
            self::fixtureDir() . '/vendor/acme/tags-core/src/Resource/App/Api/Search.php',
            $result->value->file,
        );
    }

    public function testDistinguishesInvalidInputFromMissingResource(): void
    {
        $invalid = $this->query->resolveString($this->project, 'not-a-resource-uri');
        $traversal = $this->query->resolveString($this->project, 'app://self/../../Client');
        $missing = $this->query->resolveString($this->project, 'app://self/missing');

        self::assertSame(SemanticStatus::InvalidInput, $invalid->status);
        self::assertSame(SemanticStatus::InvalidInput, $traversal->status);
        self::assertSame(SemanticStatus::NotFound, $missing->status);
        self::assertNull($invalid->value);
        self::assertNull($traversal->value);
        self::assertNull($missing->value);
    }

    public function testUnknownImportedHostIsNotFound(): void
    {
        $result = $this->query->resolveString($this->project, 'app://unknown/api/search');

        self::assertSame(SemanticStatus::NotFound, $result->status);
        self::assertNull($result->value);
        self::assertSame([], $result->candidates);
    }

    public function testReturnsAmbiguousCandidatesInDeterministicOrder(): void
    {
        $first = $this->query->resolveString($this->project, 'page://self/x');
        $second = $this->query->resolveString($this->project, 'page://self/x');

        self::assertSame(SemanticStatus::Ambiguous, $first->status);
        self::assertNull($first->value);
        self::assertSame(
            [
                self::fixtureDir() . '/src/Resource/Page/Admin/X.php',
                self::fixtureDir() . '/src/Resource/Page/Content/X.php',
            ],
            array_map(
                static fn (ResourceResolution $candidate): string => $candidate->file,
                $first->candidates,
            ),
        );
        self::assertSame(
            array_map(
                static fn (ResourceResolution $candidate): string => $candidate->file,
                $first->candidates,
            ),
            array_map(
                static fn (ResourceResolution $candidate): string => $candidate->file,
                $second->candidates,
            ),
        );
    }

    public function testDirectResourceTakesPriorityOverContextCandidates(): void
    {
        $result = $this->query->resolveString($this->project, 'page://self/y');

        self::assertSame(SemanticStatus::Ok, $result->status);
        self::assertInstanceOf(ResourceResolution::class, $result->value);
        self::assertSame(self::fixtureDir() . '/src/Resource/Page/Y.php', $result->value->file);
        self::assertSame([], $result->candidates);
    }

    public function testLegacyResolverCollapsesDetailedStatusesWithoutChangingItsContract(): void
    {
        $resolver = new ResourceTargetResolver($this->query);
        $user = ResourceUri::fromString('app://self/user');
        $ambiguous = ResourceUri::fromString('page://self/x');
        self::assertNotNull($user);
        self::assertNotNull($ambiguous);

        self::assertSame(
            [
                'file' => self::fixtureDir() . '/src/Resource/App/User.php',
                'fqn' => 'Acme\Blog\Resource\App\User',
            ],
            $resolver->resolve($this->project, $user),
        );
        self::assertNull($resolver->resolve($this->project, $ambiguous));
    }

    private static function fixtureDir(): string
    {
        return dirname(__DIR__, 3) . '/Fixture/Resource';
    }
}

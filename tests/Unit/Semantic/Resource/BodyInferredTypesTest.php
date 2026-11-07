<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Semantic\Resource;

use Composer\Autoload\ClassLoader;
use Phpactor\WorseReflection\Bridge\Composer\ComposerSourceLocator;
use Phpactor\WorseReflection\ReflectorBuilder;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Resource\WorseReflection\WorseReflectionBodyTypeResolver;

use function Amp\Promise\wait;

/**
 * Phpactor's inferred type for each value written into a Resource body, kept apart from the proven names.
 */
final class BodyInferredTypesTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../Fixture/BodyTypes';

    public function testEachValueOnThePathThatReachesTheEndGetsATypeAndTheClassesInsideIt(): void
    {
        $body = $this->method($this->describe(self::FIXTURE), 'onGet')['responseBody'];

        self::assertSame('phpactor', $body['inferredTypes']['source']);
        self::assertSame([
            'article' => ['?BodyTypes\Entity\Article', 'object', ['BodyTypes\Entity\Article']],
            'items' => ['array<int,BodyTypes\Entity\Article>', 'array', ['BodyTypes\Entity\Article']],
            'pager' => ['BodyTypes\Entity\Pager', 'object', ['BodyTypes\Entity\Pager']],
            'title' => ['"Articles"', 'scalar', []],
            // The later `$this->body['total'] = ...` replaces the value from the full assignment.
            'total' => ['int', 'scalar', []],
            'unknown' => ['<missing>', 'unknown', []],
            // An array literal reports the objects inside it too.
            'wrapped' => ['array{first:BodyTypes\Entity\Article}', 'array', ['BodyTypes\Entity\Article']],
        ], $this->types($body));
        // The proven names stay what the code shows; the inference adds no name.
        self::assertSame(array_keys($this->types($body)), $body['names']);
        // Guards return their own body and are not inferred.
        self::assertArrayNotHasKey('inferredTypes', $body['earlyExits'][0]);
    }

    public function testNothingIsInferredWhereTheNamesAreNotProvenOrWithoutPhpactor(): void
    {
        $post = $this->method($this->describe(self::FIXTURE), 'onPost')['responseBody'];
        self::assertSame('unsupported', $post['status']);
        self::assertArrayNotHasKey('inferredTypes', $post);

        $response = wait((new SemanticQueryHandler(self::FIXTURE))->describeResource('page://self/articles'));
        self::assertArrayNotHasKey('inferredTypes', $this->method($response['data'], 'onGet')['responseBody']);
    }

    public function testTypesFollowTheOtherFilesTheyDependOnWithoutTheResourceChanging(): void
    {
        $root = sys_get_temp_dir() . '/bear-body-types-' . bin2hex(random_bytes(4));
        $this->copy(self::FIXTURE, $root);
        try {
            $handler = $this->handler($root);
            self::assertSame('int', $this->totalType($handler));

            $repository = $root . '/src/Repository/ArticleRepository.php';
            file_put_contents($repository, str_replace(
                'public function total(): int',
                'public function total(): string',
                (string) file_get_contents($repository),
            ));

            self::assertSame('string', $this->totalType($handler));
        } finally {
            $this->remove($root);
        }
    }

    private function totalType(SemanticQueryHandler $handler): string
    {
        return $this->types($this->method($this->describeWith($handler), 'onGet')['responseBody'])['total'][0];
    }

    /** @return array<string, mixed> */
    private function describe(string $root): array
    {
        return $this->describeWith($this->handler($root));
    }

    /** @return array<string, mixed> */
    private function describeWith(SemanticQueryHandler $handler): array
    {
        $response = wait($handler->describeResource('page://self/articles'));
        self::assertSame('ok', $response['status']);

        return $response['data'];
    }

    private function handler(string $root): SemanticQueryHandler
    {
        // Reads the fixture's classes from disk on every lookup, as the language server's index does after a save.
        $loader = new ClassLoader();
        $loader->setPsr4('BodyTypes\\', [$root . '/src']);

        return new SemanticQueryHandler($root, bodyTypeResolver: new WorseReflectionBodyTypeResolver(
            ReflectorBuilder::create()->addLocator(new ComposerSourceLocator($loader))->build(),
        ));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function method(array $data, string $name): array
    {
        foreach ($data['methods'] as $method) {
            if ($method['name'] === $name) {
                return $method;
            }
        }
        self::fail($name . ' not described');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, array{string, string, list<string>}>
     */
    private function types(array $body): array
    {
        $types = [];
        foreach ($body['inferredTypes']['items'] as $item) {
            $types[$item['name']] = [$item['type'], $item['kind'], $item['classes']];
        }

        return $types;
    }

    private function copy(string $from, string $to): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        mkdir($to);
        foreach ($files as $file) {
            $target = $to . substr((string) $file, strlen($from));
            $file->isDir() ? mkdir($target) : copy((string) $file, $target);
        }
    }

    private function remove(string $path): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($path);
    }
}

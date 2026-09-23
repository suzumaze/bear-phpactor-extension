<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Contract;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Suzumaze\BearPhpactor\LanguageServer\SemanticQueryHandler;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;

use function Amp\Promise\wait;
use function array_keys;
use function array_map;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SemanticQueryProtocolTest extends TestCase
{
    public function testPublicContractMatchesSnapshot(): void
    {
        $snapshotContents = file_get_contents(__DIR__ . '/semantic-query-contract.json');
        self::assertNotFalse($snapshotContents);
        $expected = json_decode($snapshotContents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        $methodMap = (new SemanticQueryHandler($this->fixture('Resource')))->methods();
        $methods = [];
        foreach ($this->successCases() as $requestMethod => [$fixture, $arguments]) {
            self::assertArrayHasKey($requestMethod, $methodMap);
            $handlerMethod = $methodMap[$requestMethod];
            $handler = new SemanticQueryHandler($this->fixture($fixture));
            $response = wait($handler->{$handlerMethod}(...$arguments));

            self::assertSame('ok', $response['status'], $requestMethod);
            self::assertIsArray($response['data'], $requestMethod);

            $reflection = new ReflectionMethod(SemanticQueryHandler::class, $handlerMethod);
            $methods[$requestMethod] = [
                'handler' => $handlerMethod,
                'parameters' => array_map(
                    self::parameterContract(...),
                    $reflection->getParameters(),
                ),
                'successEnvelopeKeys' => array_keys($response),
                'successDataKeys' => array_keys($response['data']),
            ];
        }

        self::assertSame(array_keys($methodMap), array_keys($methods));

        $failure = wait((new SemanticQueryHandler($this->fixture('Resource')))->resolveResource(
            'not-a-resource-uri',
        ));
        self::assertSame('invalid_input', $failure['status']);
        self::assertIsArray($failure['error']);

        $partialFailure = wait((new SemanticQueryHandler($this->fixture('Resource')))->resolveResourceTemplate(
            'app://self/user',
            'twig',
        ));
        self::assertSame('not_found', $partialFailure['status']);
        self::assertNull($partialFailure['data']);
        self::assertIsArray($partialFailure['partial']);

        $actual = [
            'semanticApiVersion' => SemanticQueryHandler::SEMANTIC_API_VERSION,
            'semanticProtocol' => SemanticQueryHandler::SEMANTIC_PROTOCOL,
            'statuses' => array_map(
                static fn (SemanticStatus $status): string => $status->value,
                SemanticStatus::cases(),
            ),
            'failureEnvelopeKeys' => array_keys($failure),
            'partialFailureEnvelopeKeys' => array_keys($partialFailure),
            'partialFailureDataKeys' => array_keys($partialFailure['partial']),
            'errorKeys' => array_keys($failure['error']),
            'methods' => $methods,
        ];

        self::assertSame($expected, $actual);
    }

    /**
     * @return array<string,array{string,list<mixed>}>
     */
    private function successCases(): array
    {
        return [
            'bear/project/info' => ['Resource', []],
            'bear/project/diagnostics' => ['Resource', [null, 1, 0]],
            'bear/project/contractCoverage' => ['Contract', [null, 1, 0, false, null]],
            'bear/resource/resolve' => [
                'Resource',
                ['app://self/user', 'src/Client.php'],
            ],
            'bear/resource/list' => ['Resource', ['app', 'user', 1, 0]],
            'bear/resource/describe' => [
                'Template/basic',
                ['app://self/dashboard', 'src/Resource/App/Dashboard.php'],
            ],
            'bear/resource/attributes' => [
                'Template/basic',
                ['app://self/dashboard', 'src/Resource/App/Dashboard.php'],
            ],
            'bear/resource/attributeIndex' => ['Template/basic', ['app', '', 2, 0]],
            'bear/resource/incomingRelations' => [
                'Template/basic',
                ['app://self/user', 'src/Resource/App/User.php', 2],
            ],
            'bear/resource/references' => [
                'References',
                ['app://self/article', 'src/Resource/App/Article.php', 2],
            ],
            'bear/contract/compare' => [
                'Contract',
                ['app://self/user', 'onPost', 'request', null, 'src/Resource/App/User.php'],
            ],
            'bear/route/resolve' => ['Router', ['/thing/detail', 'aura.route.php']],
            'bear/sql/resolve' => [
                'Sql/App1',
                ['point_distance', 'src/Query/PointQueryInterface.php'],
            ],
            'bear/template/resolve' => [
                'Template',
                ['qiq', './sibling', 'var/qiq/template/Page/Nested/RelativeReferences.php'],
            ],
            'bear/template/forResource' => [
                'Template/basic',
                ['app://self/user', 'twig', 'src/Resource/App/User.php'],
            ],
            'bear/alps/resolveDescriptor' => [
                'Alps/App1',
                ['goArticle', 'src/Resource/App/AlpsDemo.php'],
            ],
            'bear/alps/describeDescriptor' => [
                'Alps/App1',
                ['goArticle', 'src/Resource/App/AlpsDemo.php'],
            ],
            'bear/schema/resolveNamed' => [
                'JsonSchema/basic',
                ['user-params.json', 'request', 'src/Resource/App/SchemaDemo.php'],
            ],
            'bear/schema/forResource' => [
                'JsonSchema/basic',
                ['app://self/bodyTypeDemo', 'response', 'src/Resource/App/BodyTypeDemo.php'],
            ],
            'bear/schema/describeNamed' => [
                'JsonSchema/basic',
                ['user-params.json', 'request', 'src/Resource/App/SchemaDemo.php'],
            ],
            'bear/schema/describeForResource' => [
                'JsonSchema/basic',
                ['app://self/bodyTypeDemo', 'response', 'src/Resource/App/BodyTypeDemo.php'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function parameterContract(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        $contract = [
            'name' => $parameter->getName(),
            'type' => $type->getName() . ($type->allowsNull() ? '|null' : ''),
            'required' => ! $parameter->isOptional(),
        ];
        if ($parameter->isDefaultValueAvailable()) {
            $contract['default'] = $parameter->getDefaultValue();
        }

        return $contract;
    }

    private function fixture(string $path): string
    {
        $fixture = realpath(__DIR__ . '/../Fixture/' . $path);
        self::assertNotFalse($fixture);

        return $fixture;
    }
}

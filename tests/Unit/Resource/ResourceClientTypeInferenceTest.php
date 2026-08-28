<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Tests\Unit\Resource;

use Suzumaze\BearPhpactor\Resource\WorseReflection\ResourceClientTypeResolver;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentBuilder;
use Phpactor\WorseReflection\Core\Exception\SourceNotFound;
use Phpactor\WorseReflection\Core\Inference\Walker\TestAssertWalker;
use Phpactor\WorseReflection\Core\Name;
use Phpactor\WorseReflection\Core\SourceCodeLocator;
use Phpactor\WorseReflection\ReflectorBuilder;
use PHPUnit\Framework\TestCase;

/**
 * v0.3 の型推論テスト。
 *
 * tests/Inference/*.test の wrAssertType('期待型', $式) を TestAssertWalker が
 * 検証する。リフレクタには ResourceClientTypeResolver を登録し、フィクスチャの
 * psr-4 (Acme\Blog\ → src/) と BEAR スタブ (vendor/BEAR/Resource/) を
 * ソースロケータで引けるようにする。
 */
final class ResourceClientTypeInferenceTest extends TestCase
{
    public function testResourceClientType(): void
    {
        $fixtureDir = dirname(__DIR__, 2) . '/Fixture/Resource';
        $testFile = dirname(__DIR__, 2) . '/Inference/resource_client_type.test';
        $source = (string) file_get_contents($testFile);

        $reflector = ReflectorBuilder::create()
            ->enableContextualSourceLocation()
            ->addLocator($this->fixtureLocator($fixtureDir), 1)
            ->addFrameWalker(new TestAssertWalker($this))
            ->addMemberContextResolver(new ResourceClientTypeResolver($fixtureDir))
            ->build();

        // 末尾+1バイトのオフセットは SourceFileNode を指し、ファイル全体の
        // フレームを構築する (末尾ちょうどだと最後のメソッドのスコープしか
        // 歩かないことがある)。
        $reflector->reflectOffset(TextDocumentBuilder::fromString($source), strlen($source) + 1);
    }

    /**
     * フィクスチャの psr-4 に従ってクラス名 → ファイルを引くソースロケータ。
     * テスト専用の最小実装 (本番の解決は Project が担う)。
     */
    private function fixtureLocator(string $fixtureDir): SourceCodeLocator
    {
        return new class ([
            'Acme\Blog\\' => $fixtureDir . '/src',
            'BEAR\Resource\\' => $fixtureDir . '/vendor/BEAR/Resource',
        ]) implements SourceCodeLocator {
            /**
             * @param array<string, string> $psr4 プレフィックス (末尾 '\') → ディレクトリ
             */
            public function __construct(private array $psr4)
            {
            }

            public function locate(Name $name): TextDocument
            {
                $fqn = (string) $name;
                foreach ($this->psr4 as $prefix => $dir) {
                    if (str_starts_with($fqn, $prefix)) {
                        $file = $dir . '/' . str_replace('\\', '/', substr($fqn, strlen($prefix))) . '.php';
                        if (is_file($file)) {
                            return TextDocumentBuilder::fromUri($file)->build();
                        }
                    }
                }

                throw new SourceNotFound(sprintf('Could not find source for "%s"', $fqn));
            }
        };
    }
}

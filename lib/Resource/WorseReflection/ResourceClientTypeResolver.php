<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\WorseReflection;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Model\ResourceUri;
use Phpactor\WorseReflection\Core\ClassName;
use Phpactor\WorseReflection\Core\Inference\FunctionArguments;
use Phpactor\WorseReflection\Core\Inference\Resolver\MemberAccess\MemberContextResolver;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Core\TypeFactory;
use Phpactor\WorseReflection\Core\Type\StringLiteralType;
use Phpactor\WorseReflection\Reflector;

/**
 * $this->resource->get('app://self/user') の戻り値を、URIに対応する具象
 * リソースクラスとして型付けする。
 *
 * 雛形は本体同梱の SymfonyContainerContextResolver (ContainerInterface::get()
 * のサービスIDから具象型を返す)。判定の骨格 (メソッドか / 名前が一致するか /
 * クラスが目的のインターフェースを実装しているか / 第1引数が StringLiteralType か)
 * をそのまま流用し、URI → クラス名の導出だけ既存の ResourceUri / Project に委譲する。
 *
 * 解決できないときは null を返し、呼び出し元の推論 (ResourceInterface の宣言
 * 戻り値 ResourceObject) に委ねる。例外は投げない。
 */
final class ResourceClientTypeResolver implements MemberContextResolver
{
    private const RESOURCE_INTERFACE = 'BEAR\Resource\ResourceInterface';

    /** @var list<string> */
    private const RESOURCE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    public function __construct(
        private string $projectRoot,
    ) {
    }

    public function resolveMemberContext(
        Reflector $reflector,
        ReflectionMember $member,
        Type $type,
        ?FunctionArguments $arguments
    ): ?Type {
        if ($member->memberType() !== ReflectionMember::TYPE_METHOD) {
            return null;
        }

        if (!in_array($member->name(), self::RESOURCE_METHODS, true)) {
            return null;
        }

        if ($arguments === null || count($arguments) === 0) {
            return null;
        }

        if (!$member->class()->isInstanceOf(ClassName::fromString(self::RESOURCE_INTERFACE))) {
            return null;
        }

        $argument = $arguments->at(0)->type();
        if (!$argument instanceof StringLiteralType) {
            return null;
        }

        $uri = ResourceUri::fromString($argument->value());
        if ($uri === null || $uri->host() !== 'self') {
            return null;
        }

        $project = Project::locate($this->projectRoot . '/composer.json');
        if ($project === null) {
            return null;
        }

        $classFile = $project->classFile($uri);
        $classFqn = $project->classFqn($uri);
        if ($classFile === null || $classFqn === null || !is_file($classFile)) {
            return null;
        }

        return TypeFactory::reflectedClass($reflector, ClassName::fromString($classFqn));
    }
}

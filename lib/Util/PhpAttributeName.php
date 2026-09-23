<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Util;

use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\QualifiedName;
use Phpactor\WorseReflection\Core\Util\NodeUtil;

/**
 * Resolves PHP attribute names according to namespace and import rules.
 */
final class PhpAttributeName
{
    private function __construct()
    {
    }

    public static function resolve(Attribute $attribute): ?string
    {
        if (!$attribute->name instanceof QualifiedName) {
            return null;
        }

        $resolved = $attribute->name->getResolvedName();

        return $resolved === null ? null : ltrim((string) $resolved, '\\');
    }

    /**
     * Legacy mode also accepts a written FQN without a leading backslash inside
     * a namespace. New attribute consumers should use PHP's resolved name only.
     */
    public static function is(
        Attribute $attribute,
        string $fqn,
        bool $acceptLegacyWrittenFqn = false,
    ): bool {
        $fqn = ltrim($fqn, '\\');
        if (self::resolve($attribute) === $fqn) {
            return true;
        }
        if (!$acceptLegacyWrittenFqn || !$attribute->name instanceof QualifiedName) {
            return false;
        }

        $written = NodeUtil::nameFromTokenOrQualifiedName($attribute, $attribute->name);

        return ltrim((string) $written, '\\') === $fqn;
    }
}

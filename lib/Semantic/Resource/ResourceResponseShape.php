<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Resource;

/**
 * Exact top-level response names proven from one Resource method body.
 */
final readonly class ResourceResponseShape
{
    public const REASON_COMPLEX_CONTROL_FLOW = 'complex_control_flow';
    public const REASON_DYNAMIC_ASSIGNMENT = 'dynamic_body_assignment';
    public const REASON_DYNAMIC_KEY = 'dynamic_body_key';
    public const REASON_NO_COMPLETE_ASSIGNMENT = 'no_complete_body_assignment';
    public const REASON_SELF_METHOD_CALL = 'self_method_call';
    public const REASON_UNSUPPORTED_BODY_USE = 'unsupported_body_use';
    public const REASON_UNSUPPORTED_RETURN = 'unsupported_return';

    /** @param list<string> $names */
    private function __construct(
        public bool $complete,
        public array $names,
        public ?string $reason,
    ) {
    }

    /** @param list<string> $names */
    public static function complete(array $names): self
    {
        sort($names);

        return new self(true, array_values(array_unique($names)), null);
    }

    public static function unsupported(string $reason): self
    {
        return new self(false, [], $reason);
    }
}

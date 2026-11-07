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

    /**
     * @param list<string> $names
     * @param list<array{line: int, bodyAssigned: bool, names: list<string>|null}> $earlyExits
     * @param array<string, array{int, int}> $valueRanges
     */
    private function __construct(
        public bool $complete,
        public array $names,
        public ?string $reason,
        /** guards that return before the end; `names` is null when the body there is not known */
        public array $earlyExits = [],
        /** byte start and end of the expression each name was last assigned on the path that reaches the end */
        public array $valueRanges = [],
    ) {
    }

    /**
     * @param list<string> $names
     * @param list<array{line: int, bodyAssigned: bool, names: list<string>|null}> $earlyExits
     * @param array<string, array{int, int}> $valueRanges
     */
    public static function complete(array $names, array $earlyExits = [], array $valueRanges = []): self
    {
        sort($names);
        ksort($valueRanges);

        return new self(true, array_values(array_unique($names)), null, $earlyExits, $valueRanges);
    }

    public static function unsupported(string $reason): self
    {
        return new self(false, [], $reason);
    }
}

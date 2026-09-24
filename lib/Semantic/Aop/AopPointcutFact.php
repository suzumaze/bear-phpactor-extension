<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

/**
 * One source-declared Ray.Aop pointcut; it is not a claim that weaving occurs.
 */
final readonly class AopPointcutFact
{
    public const STATE_RESOLVED = 'resolved';
    public const STATE_UNRESOLVED = 'unresolved';

    /**
     * @param array<string,mixed>|null $classMatcher
     * @param array<string,mixed>|null $methodMatcher
     * @param list<string> $interceptors
     * @param list<string> $reasons
     */
    public function __construct(
        public string $state,
        public string $module,
        public ?array $classMatcher,
        public ?array $methodMatcher,
        public array $interceptors,
        public bool $priority,
        public array $reasons,
        public string $path,
        public int $byteStart,
        public int $byteEnd,
    ) {
    }
}

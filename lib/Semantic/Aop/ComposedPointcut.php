<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\BindingOrigin;
use Suzumaze\BearPhpactor\Semantic\Di\Composition\ModuleEdge;

final readonly class ComposedPointcut
{
    public ?MatcherValue $classMatcher;
    public ?MatcherValue $methodMatcher;
    /** @var list<string> */
    public array $interceptors;
    public bool $interceptorsKnown;

    public function __construct(
        mixed $classMatcher,
        mixed $methodMatcher,
        mixed $interceptors,
        public bool $priority,
        public BindingOrigin $origin,
    ) {
        $this->classMatcher = $classMatcher instanceof MatcherValue ? $classMatcher : null;
        $this->methodMatcher = $methodMatcher instanceof MatcherValue ? $methodMatcher : null;
        $this->interceptors = is_array($interceptors)
            ? array_values(array_filter($interceptors, is_string(...))) : [];
        $this->interceptorsKnown = is_array($interceptors) && count($interceptors) === count($this->interceptors);
    }

    public function through(ModuleEdge $edge): self
    {
        return new self(
            $this->classMatcher,
            $this->methodMatcher,
            $this->interceptorsKnown ? $this->interceptors : null,
            $this->priority,
            $this->origin->through($edge),
        );
    }
}

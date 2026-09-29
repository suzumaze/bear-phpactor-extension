<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Di\Composition;

/**
 * Local state of one interpreted method call.
 */
final class Frame
{
    /** @var array<string, mixed> */
    public array $locals = [];

    /** The statement being executed, for locating unknowns reported by callees. */
    public ?\Microsoft\PhpParser\Node $statement = null;

    /** A branch whose runtime choice could change this method's return value was skipped. */
    public bool $controlFlowUnknown = false;

    /** @var list<mixed>|null values yielded by a generator method */
    public ?array $yields = null;

    public function __construct(public readonly ClassSource $declaring, public readonly ?ObjectValue $object)
    {
    }
}

<?php

declare(strict_types=1);

namespace Acme\Blog;

/** Fixture-only direct calls used to exercise project diagnostic pagination. */
final class DiagnosticClient
{
    public function request(): void
    {
        $this->resource->get('app://self/missing-diagnostic-a');
        $this->resource->post('app://self/missing-diagnostic-b');
        $this->resource->put('app://self/missing-diagnostic-c');
        $this->resource->delete('app://self/missing-diagnostic-d');
    }
}

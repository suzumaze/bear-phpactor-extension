<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Alps;

use Suzumaze\BearPhpactor\Semantic\Result\SemanticResult;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Result\Provenance;
use Suzumaze\BearPhpactor\Semantic\Workspace\WorkspaceContext;

/**
 * Describes one ALPS descriptor and its explicit local profile relationships.
 * External href/rt values remain descriptor fields and are never fetched.
 */
final class AlpsFactsQuery
{
    public function __construct(
        private AlpsProfileQuery $profileQuery = new AlpsProfileQuery(),
    ) {
    }

    /** @return SemanticResult<AlpsDescriptorFacts|null> */
    public function describeInWorkspace(
        WorkspaceContext $workspace,
        string $descriptorId,
        ?string $contextPath = null,
    ): SemanticResult {
        if ($descriptorId === '' || str_contains($descriptorId, "\0")) {
            return SemanticResult::invalidInput();
        }

        $project = $workspace->project($contextPath);
        if ($project->value === null) {
            return SemanticResult::failure($project->status);
        }

        $profile = $this->profileQuery->load($project->value);
        if ($profile->value === null) {
            return SemanticResult::failure($profile->status);
        }
        $profilePath = $workspace->accessPolicy()->inspectExisting($profile->value->file);
        if ($profilePath->value === null) {
            return SemanticResult::failure($profilePath->status);
        }

        $matches = $profile->value->descriptorsById($descriptorId);
        if ($matches === []) {
            return SemanticResult::notFound();
        }

        $relations = $this->relations($profile->value);
        $facts = [];
        foreach ($matches as $descriptor) {
            if ($descriptor->offset === null) {
                return SemanticResult::parseError();
            }
            $facts[] = $this->facts(
                $profilePath->value->absolute,
                $descriptor,
                $descriptorId,
                $descriptor->offset,
                $relations,
            );
        }

        return count($facts) === 1
            ? SemanticResult::ok(
                $facts[0],
                [Provenance::savedFile($profilePath->value->relative)],
            )
            : SemanticResult::ambiguous($facts);
    }

    /**
     * @param list<AlpsDescriptorRelationFact> $relations
     */
    private function facts(
        string $profileFile,
        AlpsProfileDescriptor $descriptor,
        string $descriptorId,
        int $descriptorOffset,
        array $relations,
    ): AlpsDescriptorFacts {
        $outgoing = [];
        $incoming = [];
        foreach ($relations as $relation) {
            if ($relation->sourceOffset === $descriptorOffset) {
                $outgoing[] = $relation;
            }
            if (
                $relation->targetId === $descriptor->id
                && (
                    $relation->targetStatus === SemanticStatus::Ambiguous
                    || $relation->targetOffset === $descriptorOffset
                )
            ) {
                $incoming[] = $relation;
            }
        }

        return new AlpsDescriptorFacts(
            new AlpsDescriptorFact(
                new AlpsDescriptorResolution(
                    $descriptorId,
                    $profileFile,
                    $descriptorOffset,
                ),
                $descriptor->type,
                $descriptor->name,
                $descriptor->rt,
                $descriptor->href,
                $descriptor->rel,
                $descriptor->doc,
                $descriptor->def,
                $descriptor->tag,
                $descriptor->title,
            ),
            $outgoing,
            $incoming,
        );
    }

    /** @return list<AlpsDescriptorRelationFact> */
    private function relations(AlpsProfile $profile): array
    {
        $relations = [];
        $this->collectRelations($profile, $profile->descriptors, null, $relations);

        $unique = [];
        foreach ($relations as $relation) {
            $key = implode("\0", [
                $relation->kind,
                $relation->sourceId ?? '',
                (string) ($relation->sourceOffset ?? -1),
                $relation->targetId,
                $relation->targetStatus->value,
                (string) ($relation->targetOffset ?? -1),
            ]);
            $unique[$key] = $relation;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * @param list<AlpsProfileDescriptor> $descriptors
     * @param list<AlpsDescriptorRelationFact> $relations
     */
    private function collectRelations(
        AlpsProfile $profile,
        array $descriptors,
        ?AlpsProfileDescriptor $owner,
        array &$relations,
    ): void {
        foreach ($descriptors as $descriptor) {
            if ($owner !== null && $descriptor->id !== null && $descriptor->offset !== null) {
                $relations[] = new AlpsDescriptorRelationFact(
                    AlpsDescriptorRelationFact::KIND_CONTAINS,
                    $owner->id,
                    $descriptor->id,
                    SemanticStatus::Ok,
                    $owner->offset,
                    $descriptor->offset,
                );
            }

            $source = $descriptor->id === null ? $owner : $descriptor;
            $hrefTarget = $this->localTargetId($descriptor->href);
            if ($hrefTarget !== null) {
                $relations[] = $this->referenceRelation(
                    $profile,
                    AlpsDescriptorRelationFact::KIND_HREF,
                    $source,
                    $hrefTarget,
                );
            }

            $rtTarget = $this->localTargetId($descriptor->rt);
            if ($rtTarget !== null && $descriptor->id !== null) {
                $relations[] = $this->referenceRelation(
                    $profile,
                    AlpsDescriptorRelationFact::KIND_RT,
                    $descriptor,
                    $rtTarget,
                );
            }

            $this->collectRelations(
                $profile,
                $descriptor->children,
                $descriptor->id === null ? $owner : $descriptor,
                $relations,
            );
        }
    }

    private function referenceRelation(
        AlpsProfile $profile,
        string $kind,
        ?AlpsProfileDescriptor $source,
        string $targetId,
    ): AlpsDescriptorRelationFact {
        $targets = $profile->descriptorsById($targetId);
        $status = match (count($targets)) {
            0 => SemanticStatus::NotFound,
            1 => SemanticStatus::Ok,
            default => SemanticStatus::Ambiguous,
        };

        return new AlpsDescriptorRelationFact(
            $kind,
            $source?->id,
            $targetId,
            $status,
            $source?->offset,
            $status === SemanticStatus::Ok ? $targets[0]->offset : null,
        );
    }

    private function localTargetId(?string $reference): ?string
    {
        if ($reference === null || !str_starts_with($reference, '#')) {
            return null;
        }
        $target = rawurldecode(substr($reference, 1));

        return $target === '' ? null : $target;
    }
}

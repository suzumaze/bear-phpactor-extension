<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Semantic\Aop;

use Suzumaze\BearPhpactor\Semantic\Di\Composition\UnknownValue;

/** A value in the source interpreter, never an application matcher instance. */
final readonly class MatcherValue
{
    /** @param list<self> $operands */
    public function __construct(public string $kind, public ?string $value = null, public array $operands = [])
    {
    }

    public static function assistedInject(): self
    {
        return new self('assistedinject', AssistedInjectMatcherRecipe::CLASS_NAME);
    }

    /** @param array<int|string, mixed> $arguments */
    public function call(string $method, array $arguments): self|UnknownValue
    {
        if ($this->kind !== 'factory') {
            return new UnknownValue();
        }
        $method = strtolower($method);
        if ($method === 'any' && $arguments === []) {
            return new self('any');
        }
        $parameter = match ($method) {
            'annotatedwith' => 'annotationName',
            'subclassesof' => 'superClass',
            'startswith' => 'prefix',
            default => null,
        };
        if ($parameter !== null && count($arguments) === 1) {
            $value = $arguments[0] ?? $arguments[$parameter] ?? null;

            return is_string($value) ? new self($method, $value) : new UnknownValue();
        }
        $count = count($arguments);
        if (
            (($method === 'logicalnot' && $count === 1)
                || (in_array($method, ['logicaland', 'logicalor'], true) && $count >= 2))
            && array_is_list($arguments)
        ) {
            foreach ($arguments as $operand) {
                if (!$operand instanceof self || $operand->kind === 'factory') {
                    return new UnknownValue();
                }
            }

            return new self($method, operands: $arguments);
        }

        return new UnknownValue();
    }

    /** @return list<string> */
    public function attributeReferences(): array
    {
        $names = match ($this->kind) {
            'annotatedwith' => $this->value !== null ? [$this->value] : [],
            'assistedinject' => ['Ray\\Di\\Di\\Assisted', 'Ray\\Di\\Di\\InjectInterface'],
            default => [],
        };
        foreach ($this->operands as $operand) {
            array_push($names, ...$operand->attributeReferences());
        }

        return array_values(array_unique($names));
    }

    public function references(string $attribute): bool
    {
        if ($this->kind === 'annotatedwith' && strcasecmp((string) $this->value, ltrim($attribute, '\\')) === 0) {
            return true;
        }
        if (
            $this->kind === 'assistedinject'
            && in_array(strtolower(ltrim($attribute, '\\')), [
                strtolower('Ray\\Di\\Di\\Assisted'),
                strtolower('Ray\\Di\\Di\\InjectInterface'),
            ], true)
        ) {
            return true;
        }
        foreach ($this->operands as $operand) {
            if ($operand->references($attribute)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'operands' => array_map(static fn (self $matcher): array => $matcher->toArray(), $this->operands),
        ];
    }
}

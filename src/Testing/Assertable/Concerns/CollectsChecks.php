<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable\Concerns;

use ExeQue\Lumberjack\DataSources\Results\Cell;

trait CollectsChecks
{
    /** @var list<array{field: string, actual: mixed, expected: string|null, passed: bool}> */
    private array $checks = [];

    public function passed(): bool
    {
        return $this->mismatches() === 0;
    }

    public function mismatches(): int
    {
        return count(array_filter($this->checks, fn (array $check): bool => ! $check['passed']));
    }

    public function describe(): string
    {
        return implode("\n", array_map(fn (array $check): string => ($check['passed'] ? '  ' : '> ').$check['field'].'  '.self::show($check['actual']).(! $check['passed'] && $check['expected'] !== null ? ' (expected '.$check['expected'].')' : ''), $this->checks));
    }

    protected function compare(string $field, mixed $actual, mixed $expected): static
    {
        return $this->check($field, $actual, Cell::equal($actual, $expected), self::show($expected));
    }

    protected function check(string $field, mixed $actual, bool $passed, ?string $expected = null): static
    {
        $this->checks[] = ['field' => $field, 'actual' => $actual, 'expected' => $expected, 'passed' => $passed];

        return $this;
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

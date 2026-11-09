<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\DataSources\Concerns;

use ExeQue\Lumberjack\DataSources\Results\Cell;
use PHPUnit\Framework\Assert;

/**
 * What the assertable results share: comparing as a widget would see it, and a
 * failure that shows the data with the row that is wrong marked.
 */
trait ComparesResults
{
    private const int SHOWN_LINES = 20;

    /** @return list<string> one line per point or row */
    abstract protected function lines(): array;

    protected function same(mixed $actual, mixed $expected): bool
    {
        return Cell::equal($actual, $expected);
    }

    protected function show(mixed $value): string
    {
        return is_string($value) ? '"'.$value.'"' : (string) json_encode($value);
    }

    /** A position from either end, which fails the test when there is nothing there. */
    protected function positionOf(int $index, int $count): int
    {
        $position = $index < 0 ? $count + $index : $index;
        $this->check($position >= 0 && $position < $count, 'There is nothing at position '.$index.'; there are '.$count.'.');

        return $position;
    }

    protected function check(bool $passed, string $message, ?int $wrongLine = null): static
    {
        Assert::assertTrue($passed, $passed ? '' : $message."\n".$this->rendered($wrongLine));

        return $this;
    }

    /**
     * @param  list<int|float|string|null>  $values
     * @return int|null the first place that breaks the order, or null when it holds
     */
    protected function outOfOrder(array $values, bool $descending): ?int
    {
        for ($i = 1, $count = count($values); $i < $count; $i++) {
            // Only like with like: PHP's order of null, text and numbers is not an order.
            $comparable = (is_numeric($values[$i]) && is_numeric($values[$i - 1]) && ! is_string($values[$i]) && ! is_string($values[$i - 1])) || (is_string($values[$i]) && is_string($values[$i - 1]));

            $order = is_string($values[$i]) && is_string($values[$i - 1]) ? strcmp($values[$i], $values[$i - 1]) : $values[$i] <=> $values[$i - 1];

            if (! $comparable || ($descending ? $order > 0 : $order < 0)) {
                return $i;
            }
        }

        return null;
    }

    private function rendered(?int $wrongLine): string
    {
        $lines = $this->lines();

        if ($lines === []) {
            return '  (empty)';
        }

        $start = max(0, min(($wrongLine ?? 0) - (int) (self::SHOWN_LINES / 2), count($lines) - self::SHOWN_LINES));
        $shown = array_slice($lines, $start, self::SHOWN_LINES, true);

        return implode("\n", array_map(fn (int $i, string $line): string => ($i === $wrongLine ? '> ' : '  ').$line, array_keys($shown), $shown));
    }
}

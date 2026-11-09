<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Testing\Assertable;

use ExeQue\Lumberjack\Testing\Assertable\Concerns\CollectsChecks;
use ExeQue\Lumberjack\Testing\RecordedAlert;

/** A custom alert as Lumberjack would receive it, with the server's defaults filled in. */
final class AssertableAlert implements AssertableRecord
{
    use CollectsChecks;

    public function __construct(
        public readonly RecordedAlert $alert,
    ) {
    }

    public function hasTitle(string $title): static
    {
        return $this->compare('title', $this->alert->title, $title);
    }

    public function toTopic(string $topic): static
    {
        return $this->compare('topic', $this->alert->topic, $topic);
    }

    public function hasLevel(string $level): static
    {
        return $this->compare('level', $this->alert->level, $level);
    }

    public function isCritical(): static
    {
        return $this->hasLevel('critical');
    }

    public function isWarning(): static
    {
        return $this->hasLevel('warning');
    }

    public function hasMessage(string $message): static
    {
        return $this->compare('message', $this->alert->message, $message);
    }

    public function hasUrl(string $url): static
    {
        return $this->compare('url', $this->alert->url, $url);
    }

    public function hasField(string $name, ?string $value = null): static
    {
        $fields = array_column($this->alert->fields, 'value', 'name');
        $found = array_key_exists($name, $fields);

        return func_num_args() === 1 || ! $found
            ? $this->check('field '.$name, $found ? $fields[$name] : '(missing)', $found, 'it to be there')
            : $this->compare('field '.$name, $fields[$name], $value);
    }

    public function hasLink(string $label, ?string $url = null): static
    {
        $links = array_column($this->alert->links, 'url', 'label');
        $found = array_key_exists($label, $links);

        return func_num_args() === 1 || ! $found
            ? $this->check('link '.$label, $found ? $links[$label] : '(missing)', $found, 'it to be there')
            : $this->compare('link '.$label, $links[$label], $url);
    }

    public function hasDedupeKey(string $key): static
    {
        return $this->compare('dedupe key', $this->alert->dedupeKey, $key);
    }
}

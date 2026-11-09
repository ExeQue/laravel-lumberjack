<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack;

use Illuminate\Support\Traits\Conditionable;

/**
 * A custom alert being put together. Nothing leaves until send().
 */
class PendingAlert
{
    use Conditionable;

    /** @var array<string, mixed> */
    private array $event;

    public function __construct(private readonly Recorder $recorder, string $title)
    {
        $this->event = ['type' => 'alert', 'title' => $title];
    }

    /** The topic, which decides the channels. Without one it goes to "default". */
    public function to(string $topic): static
    {
        return $this->with('topic', $topic);
    }

    public function message(string $message): static
    {
        return $this->with('message', $message);
    }

    /** One of info, success, warning or critical. */
    public function level(string $level): static
    {
        return $this->with('level', $level);
    }

    public function info(bool $when = true): static
    {
        return $when ? $this->level('info') : $this;
    }

    public function success(bool $when = true): static
    {
        return $when ? $this->level('success') : $this;
    }

    public function warning(bool $when = true): static
    {
        return $when ? $this->level('warning') : $this;
    }

    public function critical(bool $when = true): static
    {
        return $when ? $this->level('critical') : $this;
    }

    /** Where the title links to. */
    public function url(string $url): static
    {
        return $this->with('url', $url);
    }

    public function field(string $name, string $value, bool $inline = false): static
    {
        $this->event['fields'][] = ['name' => $name, 'value' => $value, 'inline' => $inline];

        return $this;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function table(array $headers, array $rows, ?string $title = null): static
    {
        $this->event['tables'][] = ['title' => $title, 'headers' => $headers, 'rows' => $rows];

        return $this;
    }

    public function link(string $label, string $url, bool $primary = false, bool $danger = false): static
    {
        $this->event['links'][] = ['label' => $label, 'url' => $url, 'style' => $danger ? 'danger' : ($primary ? 'primary' : null)];

        return $this;
    }

    /** A repeat of the key within the minutes is listed in Lumberjack, but not sent. */
    public function dedupe(string $key, int $minutes = 10): static
    {
        $this->event['dedupe_key'] = $key;
        $this->event['dedupe_minutes'] = $minutes;

        return $this;
    }

    /** Hands it to the agent right away, not at the end of the request or job. */
    public function send(): void
    {
        $this->recorder->record($this->event);
        $this->recorder->flush();
    }

    private function with(string $key, string $value): static
    {
        $this->event[$key] = $value;

        return $this;
    }
}

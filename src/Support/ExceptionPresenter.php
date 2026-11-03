<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an exception into the shape Lumberjack expects: class, message,
 * location and frames, with a few lines of code around each app frame.
 */
class ExceptionPresenter
{
    public const int MAX_FRAMES = 100;

    public const int CODE_CONTEXT = 5;

    /** @var array<string, list<string>|null> */
    private array $files = [];

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array{class: class-string, message: string, file: string, line: int, stacktrace: list<array<string, mixed>>}
     */
    public function present(Throwable $e): array
    {
        // Each trace entry names the function that was running at the previous location.
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();

        foreach ($e->getTrace() as $entry) {
            $frames[] = ['file' => $file, 'line' => $line, 'function' => $entry['function'], 'class' => $entry['class'] ?? null];
            $file = $entry['file'] ?? '[internal]';
            $line = $entry['line'] ?? 0;
        }

        $frames[] = ['file' => $file, 'line' => $line, 'function' => null, 'class' => null];

        $this->files = [];

        return [
            'class' => $e::class,
            'message' => $this->message($e),
            'file' => $this->relative($e->getFile()),
            'line' => $e->getLine(),
            'stacktrace' => array_map($this->frame(...), array_slice($frames, 0, self::MAX_FRAMES)),
        ];
    }

    /**
     * The message, but a query's without the values bound to it: they can be anything the
     * app stored, passwords and personal data included. The SQL keeps its placeholders.
     */
    private function message(Throwable $e): string
    {
        $message = $e->getMessage();

        if (! $e instanceof QueryException || $e->getBindings() === []) {
            return $message;
        }

        try {
            $clean = str_replace(Str::replaceArray('?', $e->getBindings(), $e->getSql()), $e->getSql(), $message);
        } catch (Throwable) {
            $clean = $message;
        }

        if ($clean !== $message) {
            return $clean;
        }

        // Laravel writes "<driver's message> (Connection: ..., SQL: <sql with values>)".
        $previous = (string) $e->getPrevious()?->getMessage();
        $at = strpos($message, ', SQL: ', strlen($previous));

        return $at !== false ? substr($message, 0, $at).', SQL: '.$e->getSql().')' : $previous.' (SQL: '.$e->getSql().')';
    }

    /**
     * @param  array{file: string, line: int, function: ?string, class: ?string}  $frame
     * @return array<string, mixed>
     */
    private function frame(array $frame): array
    {
        $inApp = $this->inApp($frame['file']);

        return array_filter([
            'file' => $this->relative($frame['file']),
            'line' => $frame['line'],
            'function' => $frame['function'],
            'class' => $frame['class'],
            'in_app' => $inApp,
            'code' => $inApp ? $this->code($frame['file'], $frame['line']) : null,
        ], fn ($value): bool => $value !== null);
    }

    private function inApp(string $file): bool
    {
        return str_starts_with($file, $this->basePath) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->basePath.DIRECTORY_SEPARATOR)
            ? substr($file, strlen($this->basePath) + 1)
            : $file;
    }

    /**
     * @return array{start: int, lines: list<string>}|null
     */
    private function code(string $file, int $line): ?array
    {
        if (! array_key_exists($file, $this->files)) {
            $lines = is_file($file) && is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
            $this->files[$file] = $lines === false ? null : $lines;
        }

        $lines = $this->files[$file];

        if ($lines === null || $line < 1) {
            return null;
        }

        $start = max(1, $line - self::CODE_CONTEXT);

        return [
            'start' => $start,
            'lines' => array_slice($lines, $start - 1, self::CODE_CONTEXT * 2 + 1),
        ];
    }
}

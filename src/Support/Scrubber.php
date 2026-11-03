<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * Blanks secrets before anything leaves the app: values under sensitive keys, query-string
 * values, credentials in strings (bearer tokens, JWTs, DSN passwords, AWS and Stripe keys).
 * It also makes context plain data when it is recorded, so nothing of the app's runs later,
 * when the batch is encoded: objects become what they are about, deep or long values are cut.
 */
final class Scrubber
{
    public const string FILTERED = '[filtered]';

    public const int MAX_DEPTH = 8;

    public const int MAX_ITEMS = 100;

    public const int MAX_STRING = 16_384;

    /** Keys whose values never leave the app; `*` is a wildcard, `-` and `_` are the same. */
    public const array DEFAULT_KEYS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'x-auth-token',
        'x-csrf-token', 'x-xsrf-token', 'php-auth-pw', 'password', 'password_confirmation', 'passwd',
        'secret', 'token', 'api_key', 'apikey', 'access_token', 'refresh_token', 'client_secret',
        'card', 'card_number', 'cvv', 'cvc', 'iban', 'ssn', 'private_key', '_token',
        '*secret*', '*password*', '*token*', '*api_key*', '*apikey*', '*private_key*',
    ];

    /** Credentials in any string, with what they are replaced by. */
    private const array VALUE_PATTERNS = [
        // scheme://user:password@host keeps the user, drops the password.
        '#\b([a-z][a-z0-9+.\-]*://[^:@/\s]+):[^@/\s]+@#i' => '$1:'.self::FILTERED.'@',
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 '.self::FILTERED,
        '/\beyJ[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]+/' => self::FILTERED,
        '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => self::FILTERED,
        '/\b(?:sk|rk)_(?:live|test)_[0-9A-Za-z]{10,}\b/' => self::FILTERED,
        '/\bwhsec_[0-9A-Za-z]{16,}\b/' => self::FILTERED,
    ];

    /** Event fields holding context to walk. */
    private const array CONTEXTS = ['context', 'parameters', 'arguments', 'options', 'attributes'];

    private readonly string $keys;

    /** @var array<string, string> */
    private readonly array $patterns;

    /** @var array<string, true> */
    private readonly array $queryKeys;

    /**
     * @param  list<string>  $keys  sensitive keys, `*` wildcards
     * @param  list<string>  $patterns  extra regular expressions whose matches are blanked
     * @param  list<string>  $sessionKeys  the only session keys sent
     * @param  list<string>  $queryKeys  query-string keys whose values are sent
     */
    public function __construct(
        array $keys = self::DEFAULT_KEYS,
        array $patterns = [],
        private readonly array $sessionKeys = [],
        array $queryKeys = [],
    ) {
        $globs = array_map(fn (string $key): string => str_replace('\*', '.*', preg_quote(self::normalize($key), '/')), $keys);
        $this->keys = $globs === [] ? '/(?!)/' : '/^(?:'.implode('|', $globs).')$/';
        $this->patterns = [...self::VALUE_PATTERNS, ...array_fill_keys(array_filter($patterns, fn (string $p): bool => @preg_match($p, '') !== false), self::FILTERED)];
        $this->queryKeys = array_fill_keys($queryKeys, true);
    }

    /**
     * From the scrub section of the config. A plain list (the old shape) is extra keys.
     *
     * @param  array<mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $list = fn (mixed $value): array => array_values(array_filter((array) $value, fn (mixed $item): bool => is_string($item) && $item !== ''));

        if (array_is_list($config)) {
            return new self([...self::DEFAULT_KEYS, ...$list($config)]);
        }

        $keys = $list($config['keys'] ?? []);

        return new self(
            ($config['replace_defaults'] ?? false) === true ? $keys : [...self::DEFAULT_KEYS, ...$keys],
            $list($config['patterns'] ?? []),
            $list($config['session_keys'] ?? []),
            $list($config['query_keys'] ?? []),
        );
    }

    /**
     * An event as it may leave the app.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function event(array $event): array
    {
        foreach (self::CONTEXTS as $field) {
            if (isset($event[$field]) && is_array($event[$field])) {
                $event[$field] = $this->walk($event[$field], 0);
            }
        }

        if (isset($event['message']) && is_string($event['message'])) {
            $event['message'] = $this->string($event['message']);
        }

        if (isset($event['exception']['message']) && is_string($event['exception']['message'])) {
            $event['exception']['message'] = $this->string($event['exception']['message']);
        }

        if (isset($event['url']) && is_string($event['url'])) {
            $event['url'] = $this->url($event['url']);
        }

        return $event;
    }

    /**
     * Context made plain and blanked where it is sensitive.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function context(array $data): array
    {
        return $this->walk($data, 0);
    }

    /**
     * A URL with each query-string value blanked, unless its key is allowed.
     */
    public function url(string $url): string
    {
        $at = strpos($url, '?');

        if ($at === false) {
            return $this->string($url);
        }

        $pairs = [];

        foreach (explode('&', substr($url, $at + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $name = (string) strtok(urldecode($key), '[');
            $pairs[] = $key.($value === null ? '' : '='.(isset($this->queryKeys[$name]) ? $this->string($value) : self::FILTERED));
        }

        return $this->string(substr($url, 0, $at)).($pairs === [] ? '' : '?'.implode('&', $pairs));
    }

    /**
     * Only the session keys the app allows.
     *
     * @param  array<mixed>  $session
     * @return array<mixed>
     */
    public function session(array $session): array
    {
        return $this->walk(array_intersect_key($session, array_flip($this->sessionKeys)), 0);
    }

    public function isSensitive(int|string $key): bool
    {
        return is_string($key) && preg_match($this->keys, self::normalize($key)) === 1;
    }

    /**
     * Credentials in a string blanked, and the string cut when long.
     */
    public function string(string $value): string
    {
        if (strlen($value) > self::MAX_STRING) {
            $value = mb_strcut($value, 0, self::MAX_STRING).'…';
        }

        return (string) (preg_replace(array_keys($this->patterns), array_values($this->patterns), $value) ?? $value);
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function walk(array $data, int $depth): array
    {
        $out = [];
        $count = 0;

        foreach ($data as $key => $value) {
            if ($count++ >= self::MAX_ITEMS) {
                $out['…'] = 'more';

                break;
            }

            $out[$key] = $this->isSensitive($key) ? self::FILTERED : $this->value($value, $depth + 1);
        }

        return $out;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return $this->string($value);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[…]';
        }

        // Called while recording, inside Safely: what the app's objects say about themselves
        // is taken now, never later when the batch is encoded. A model is just Class#id, so
        // no accessor or lazy load runs.
        try {
            return match (true) {
                is_array($value) => $this->walk($value, $depth),
                $value instanceof Model => $value::class.'#'.(is_scalar($value->getKey()) ? $value->getKey() : '?'),
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof Throwable => $value::class.': '.$this->string($value->getMessage()),
                $value instanceof Collection => $this->walk($value->all(), $depth),
                $value instanceof LazyCollection => $value::class,
                $value instanceof JsonSerializable => $this->value($value->jsonSerialize(), $depth + 1),
                $value instanceof Arrayable => $this->walk($value->toArray(), $depth),
                is_object($value) => $value::class,
                default => get_debug_type($value),
            };
        } catch (Throwable) {
            return is_object($value) ? $value::class : get_debug_type($value);
        }
    }

    private static function normalize(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }
}

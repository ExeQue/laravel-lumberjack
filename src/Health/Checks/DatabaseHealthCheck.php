<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Health\Checks;

use ExeQue\Lumberjack\Contracts\HealthCheck;
use ExeQue\Lumberjack\Health\CheckResult;
use ExeQue\Lumberjack\Health\Concerns\ConfiguresHealthCheck;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The database answers `select 1`, in time. Along with it come what the server can tell
 * about itself: the server's open client connections against its limit, and the size of
 * this connection's database, for PostgreSQL, MySQL and MariaDB; the file's size for
 * SQLite. Those figures never fail
 * the check, only the connection share past `warnAtConnections` turns it to a warning.
 */
final class DatabaseHealthCheck implements HealthCheck
{
    use ConfiguresHealthCheck;

    public function __construct(
        private readonly ?string $connection = null,
        private readonly int $degradeAfter = 2,
        private readonly int $recoverAfter = 5,
        private readonly int $downAfter = 5,
        private readonly ?int $maxLatencyMs = null,
        private readonly int $timeout = 10,
        private readonly ?string $slug = null,
        /** Warn when open connections reach this share of the server's limit, 0 to 1. */
        private readonly ?float $warnAtConnections = null,
    ) {
    }

    public function run(): CheckResult
    {
        $db = DB::connection($this->connection);
        $start = hrtime(true);
        $db->select('select 1');
        $latency = self::since($start);
        $metrics = ['latency_ms' => $latency, ...$this->stats($db)];
        $share = isset($metrics['connections'], $metrics['max_connections']) && $metrics['max_connections'] > 0
            ? $metrics['connections'] / $metrics['max_connections']
            : null;

        if ($this->maxLatencyMs !== null && $latency > $this->maxLatencyMs) {
            return CheckResult::warning("Took {$latency} ms, more than {$this->maxLatencyMs} ms.", $metrics);
        }

        if ($this->warnAtConnections !== null && $share !== null && $share >= $this->warnAtConnections) {
            return CheckResult::warning("{$metrics['connections']} of {$metrics['max_connections']} connections in use.", $metrics);
        }

        return CheckResult::ok(metrics: $metrics);
    }

    protected function defaultSlug(): string
    {
        return 'database:'.($this->connection ?? config('database.default'));
    }

    /**
     * What the server says about itself. A driver it does not know, or a user without the
     * rights to ask, simply gives fewer figures.
     *
     * @return array<string, int>
     */
    private function stats(Connection $db): array
    {
        try {
            return match ($db->getDriverName()) {
                'pgsql' => $this->postgres($db),
                'mysql', 'mariadb' => $this->mysql($db),
                'sqlite' => $this->sqlite($db),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, int> */
    private function postgres(Connection $db): array
    {
        $row = (array) $db->selectOne(
            // Connections are the server's, like its limit; the size is this database's.
            'select (select count(*) from pg_stat_activity where backend_type = \'client backend\') as connections,
                current_setting(\'max_connections\')::int as max_connections,
                pg_database_size(current_database()) as size_bytes',
        );

        return array_map(intval(...), $row);
    }

    /** @return array<string, int> */
    private function mysql(Connection $db): array
    {
        $status = (array) $db->selectOne("show global status like 'Threads_connected'");
        $max = (array) $db->selectOne("show variables like 'max_connections'");
        $size = (array) $db->selectOne(
            'select coalesce(sum(data_length + index_length), 0) as size_bytes from information_schema.tables where table_schema = database()',
        );

        return [
            'connections' => (int) ($status['Value'] ?? 0),
            'max_connections' => (int) ($max['Value'] ?? 0),
            'size_bytes' => (int) ($size['size_bytes'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function sqlite(Connection $db): array
    {
        $file = (string) $db->getDatabaseName();

        return $file !== ':memory:' && is_file($file) ? ['size_bytes' => (int) filesize($file)] : [];
    }
}

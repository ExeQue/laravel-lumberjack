# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

## [0.3.0] - 2026-11-09

### Added

- `DataRange` has `bucket`, `timezone` and `buckets()`: Lumberjack says how a series is to be grouped. `inBuckets()` asks the same way in a test.

## [0.2.0] - 2026-11-09

### Added

- `Lumberjack::assertRecorded()`, `assertAlerted()` and `assertReported()` take a closure typed `AssertableEvent`, `AssertableAlert` or `AssertableReport`, with assertions and a failure that shows the closest record.
- Data sources: `Lumberjack::dataSources()->add()` offers figures, series, lists and tables to status screens. Connect the app once in Lumberjack, which then pulls them itself, one source per call, over a signed, https-only endpoint that answers 404 to everything unsigned. `Lumberjack::dataSources()->define()` does the same from a closure, with no class. A source returns a typed result (`NumberResult`, `SeriesResult`, `ListResult`, `TableResult`), and its shape follows from the declared return type. `InteractsWithDataSources` gives tests `$this->dataSource()` with assertions on numbers, series, lists and tables. `#[CacheFor]` keeps an answer per range, and `lumberjack:data-sources:test` checks the setup. Needs `LUMBERJACK_DATA_SOURCES_ENABLED=true` and `LUMBERJACK_PULL_TOKEN`.

## [0.1.6] - 2026-11-09

### Added

- Health checks `tcp()`, `directorySize()` and `horizonQueues()`.
- The disk check on a local disk and the cache check on a file store report the size of their folder as `directory_bytes`.

## [0.1.5] - 2026-11-09

### Added

- A scheduled task's event carries its description, from the schedule or else from the artisan command itself.

## [0.1.4] - 2026-11-09

### Fixed

- Data and custom alerts sent while the agent is away, for example restarting in a deploy, wait on disk and are sent once it is back instead of being lost. Needs `exeque/lumberjack-agent` 0.1.2.

## [0.1.3] - 2026-11-09

### Added

- A scheduled task's event carries the command it runs, also when the task has a description.

## [0.1.2] - 2026-11-09

### Added

- `Lumberjack::fake()` for an app's tests: `assertRecorded()` and `assertNotRecorded()` for every event type, `assertReported()` for exceptions, and `assertAlerted()` and `assertNotAlerted()` for custom alerts.

## [0.1.1] - 2026-11-09

### Added

- Custom alerts: `Lumberjack::alert()` with a topic, message, levels with conditions, fields, tables, links and dedupe, on a `Conditionable` builder. Needs Lumberjack server 0.1.4 and agent 0.1.1.
- The Boost skill covers custom alerts.
- The docs site has the Lumberjack logo and favicon.

## [0.1.0] - 2026-11-05

First public release.

[Unreleased]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.6...v0.2.0
[0.1.6]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/laravel-lumberjack/releases/tag/v0.1.0

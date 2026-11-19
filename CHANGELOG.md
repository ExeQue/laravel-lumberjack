# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

### Added

- **AI token usage**: every AI call is recorded as an `ai_call` with provider, model, operation, tokens (input with cached tokens included, output, cache reads and writes, reasoning), duration, status, the key's fingerprint, label and owner, and the actor. Always at sample rate 1.0, whatever the request or job sampling: it is accounting. The API key never leaves the app, only `sha256("driver:key")`; prompts and responses are never sent. Requires Lumberjack server 0.13.0 or newer.
- Laravel AI (0.11 and 1.x) and Prism (0.100 to 0.103) are recorded on their own when installed: every agent step (text, structured, streamed), embeddings, images, audio, transcriptions, reranking and classification, failed calls and failovers included. A stream read only in part is still recorded, without tokens for Laravel AI. A newer Prism than 0.103 is left alone, said once in the logs. Each client's token counts are normalized to the same meaning per driver.
- `Lumberjack::ai(fn () => ..., by:, owner:, label:)` sets who calls are made for, such as a tenant bringing its own key; scopes nest, carry over to a stream or streamed response returned from the callback (and to AI calls its tools make, unless a tool opens its own scope) and are cleared for each request and job. `Lumberjack::resolveAiKeyUsing()` works them out per call, with `AiCall::usesKey()` telling which key was used. The actor defaults to the logged in user while a request is served.
- A Laravel AI agent can say who its calls are for by implementing `HasLumberjackAiOwner`, `HasLumberjackAiActor` and `HasLumberjackAiLabel`, one method each, read once per run; each counts for the agent's own steps over the scopes around it, and for the calls its tools, plain sub-agents and conversation title make.
- `Lumberjack::aiUsage()` records a call the app made itself. Fingerprints hash the driver in lowercase, so `OpenAI` and `openai` give the same one.
- `Lumberjack::aiCalls()`, `assertAiCallRecorded()`, `assertAiCallNotRecorded()` and `EventType::AiCall` under `Lumberjack::fake()`.
- Config `ai.enabled` (`LUMBERJACK_AI`), `ai.laravel_ai` and `ai.prism`.

## [0.11.0] - 2026-11-17

### Added

- `php artisan about` has a Lumberjack section: the SDK version, whether it is enabled, the server URL and the environment.

## [0.10.0] - 2026-11-13

### Added

- **Activity**: a class implementing `ActsAsLumberjackActor` says who it is with `toLumberjackActor()`, returning an `Actor` (a type and a key), such as a user model that stands for an API client. `by()`, `byUser()`, `on()`, `@path` in `#[RecordsLumberjackActivity]`, a model in the payload and `forgetActor()` use it in place of the class and key. `by()` and `forgetActor()` also take an `Actor`.
- The Boost guideline and the `lumberjack-development` skill cover activity: how to name one (`{thing}.{past-tense verb}`, such as `order.placed`), what a group is for (the feature area), actors and forgetting. The naming is in the docs too.

## [0.9.0] - 2026-11-13

### Added

- **Activity**: `Lumberjack::activity('order.placed')` records what happens in the app, with `group()`, `byUser()`, `by()` (any model, or a type and key), `on()` for each subject (an upload is done to the image and its gallery) and `with()` for a payload, sent with the request or job on `send()`. Nobody is the actor unless the code says so, and an actor, a subject or a model in the payload is sent as its type and key only. Requires a Lumberjack server with activity.
- An event class records itself when dispatched, with `#[RecordsLumberjackActivity]` (payload values, the actor and the subjects read from the event with `@path`, such as `@order.uuid`) or by implementing `TracksLumberjackActivity` and returning a `PendingActivity` from `toLumberjackActivity()`.
- `Lumberjack::forgetActor($user)` deletes an actor's activity in Lumberjack, such as when an account is deleted, and throws `ForgetActorFailedException` when it did not happen.
- `Lumberjack::assertActivityRecorded()`, `assertActivityNotRecorded()`, `activities()`, `assertActorForgotten()` and `EventType::Activity` under `Lumberjack::fake()`.

## [0.8.0] - 2026-11-12

### Added

- `php artisan lumberjack:sourcemaps --dry-run` lists each map with the URL path and release it would be sent with, running the same checks, without uploading or deleting anything.

## [0.7.0] - 2026-11-11

### Added

- `php artisan lumberjack:sourcemaps` uploads a build's source maps to Lumberjack, tagged with the release, so browser errors from minified bundles show the original code. `--path` picks the directory inside `public`, `--delete` removes each map once uploaded, `--no-fail` keeps a deploy going when Lumberjack is down. A 429 is retried after its `Retry-After`; once Lumberjack cannot be reached, the remaining maps are skipped.
- `artisan down` and `artisan up` are reported to Lumberjack as maintenance, straight away, and through the agent when Lumberjack cannot be reached. Turned off with `LUMBERJACK_MAINTENANCE=false`; `LUMBERJACK_MAINTENANCE_EXPECTED_MINUTES` sends how long it should take.
- `EventType::Maintenance`, for asserting on maintenance reports under `Lumberjack::fake()`.

### Changed

- The agent no longer follows a redirect from `LUMBERJACK_URL`: a batch stays in the spool instead of going elsewhere. Following one never got through anyway, as a redirect loses the token. Set `LUMBERJACK_URL` to the address Lumberjack answers on, such as `https://` when a proxy upgrades http.

## [0.6.1] - 2026-11-09

### Fixed

- **Data sources** is linked in the documentation sidebar and the README.

## [0.6.0] - 2026-11-09

### Added

- `#[Group('Events', 'Storage')]` files a data source under a nested path in Lumberjack's data source picker. The catalog carries it as `group`, and older servers ignore it.

## [0.5.0] - 2026-11-09

### Added

- `SeriesResult::runningCount()` and `SeriesResult::runningSum()` give a running total per bucket, starting from everything before the range.

## [0.4.0] - 2026-11-09

### Added

- `SeriesResult::count()` and `SeriesResult::sum()` group a query into the buckets Lumberjack asks for, by `created_at` or another column.

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

[Unreleased]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.6...v0.2.0
[0.1.6]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.5...v0.1.6
[0.1.5]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.4...v0.1.5
[0.1.4]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.2...v0.1.3
[0.1.2]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/laravel-lumberjack/releases/tag/v0.1.0

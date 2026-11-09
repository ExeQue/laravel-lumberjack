# Changelog

All notable changes are written here, newest first, in the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. A release's section is also its release notes on GitHub.

## [Unreleased]

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

[Unreleased]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/ExeQue/laravel-lumberjack/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/ExeQue/laravel-lumberjack/releases/tag/v0.1.0

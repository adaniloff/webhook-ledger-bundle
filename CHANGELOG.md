# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Project metadata: license switched from `proprietary` to `MIT` (`LICENSE` file added), `composer.json` gained `authors`, `keywords`, `homepage` and `support`.

## [1.0.2] - 2026-09-17

### Changed

- **Breaking**: the webhook reception route moved from `/webhook/{source}` to `/wl/webhook/{source}`, so it can no longer collide with a route the consuming app defines on the same path shape (e.g. `/webhook/{uuid}`).

## [1.0.1] - 2026-09-17

### Added

- `EnforceOutboxConnectionPass`: fails the container build if the Messenger transport carrying `ProcessWebhookEvent` doesn't target the same Doctrine connection as the EntityManager, or if `auto_setup=0` isn't set on that transport and its failure transport. The `auto_setup=0` requirement is only enforced on engines without transactional DDL (MySQL/MariaDB); PostgreSQL, SQLite, SQL Server and Firebird are exempt.

## [1.0.0] - 2026-09-16

### Added

- Initial release: idempotent webhook reception (`(source, external_event_id)` deduplication), transactional outbox via a Doctrine Messenger transport, retry with backoff and jitter, dead-letter replay guarded by optimistic locking.

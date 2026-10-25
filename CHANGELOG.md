# Changelog

All notable changes to Echo.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased]

## [0.1.0] — 2026-10-25

First release: the owner's emails of a Symfony site, generalised from the owner
emails and morning digests of two existing sites.

### Added
- `OwnerEcho::event($name, $context)`: counts the event for the day and, depending on
  its mode, emails the owner at once (`immediate`), keeps it for the digest (`digest`),
  only counts it (`count`) or drops it (`ignore`). Never throws: failures are logged.
- `EchoEvent`, the same call through the event dispatcher or as a Messenger message.
- Per event: `label`, `min_amount` (an event below it, such as a test payment, is only
  counted), `skip_bots` (crawlers, monitors, link previews and scripts are not counted)
  and `throttle`.
- Throttle: at most `throttle` immediate emails per hour and per event (default 10).
- Quiet hours (`from`, `to`, `weekends`), read in the configured `timezone`.
- `echo:digest`: one email with yesterday's counters and notable events; nothing when
  nothing happened; once per day (`--force` to resend), `--day`, `--dry-run`; deletes
  notable events older than `retention_days`.
- Plain-text emails only, no tracking, `Auto-Submitted: auto-generated`.
- Tables `echo_counter` and `echo_entry` (Doctrine entities, mapped automatically),
  written through DBAL with a native upsert on PostgreSQL, MySQL/MariaDB and SQLite.
- Texts in English and French (translation domain `echo`), with labels for common events.
- Flex recipe (`recipe/`).

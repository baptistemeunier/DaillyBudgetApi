# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

@AGENTS.md

> Note: `composer.json` actually requires **API Platform 5.x** (`api-platform/symfony ^5.0`) on **Symfony 8.1** / **PHP ≥ 8.4**, despite AGENTS.md saying 4.x. Still treat the online docs as the source of truth.

## Project

dailyBudget is a stateless JSON API (no frontend) that connects users' bank accounts through the **GoCardless Bank Account Data** API (requisitions → accounts → transactions). Default output format is HAL (`application/hal+json`); JSON and JSON-LD are also enabled. Swagger UI is served at `/`.

## Commands

```bash
docker compose up -d database                 # PostgreSQL 16 (only service in compose.yaml)
symfony serve  # or: php -S localhost:8000 -t public
bin/console doctrine:migrations:diff          # generate a migration after changing entities
bin/console doctrine:migrations:migrate
bin/console messenger:consume async push scheduler_default -vv  # run the worker: account syncs, notifications, Web Push deliveries and the scheduled tasks
bin/console app:notifications:daily          # run the daily notification task now (then consume async)
bin/console app:notifications:test-push [user] [--type=MONTH_CLOSING] # list the push devices, or send a test push (or a sample notification of a type) to a user's devices; synchronous, prints the push service answer
bin/console debug:router                      # list API routes (API Platform route names: _api_/<path>{._format}_<op>)
bin/console cache:clear
```

There is no test suite, PHPUnit, linter or static analyser installed yet.

Environment: real values live in `.env.dev.local` (not committed). Required: `DATABASE_URL`, `OAUTH2_CONFIGURATION_URL` (OIDC discovery URL), `OAUTH2_AUDIENCE` (required `aud` of the JWT access tokens), `GOCARDLESS_BASE_URL` (not declared in `.env`), `GOCARDLESS_CLIENT_ID`, `GOCARDLESS_CLIENT_SECRET`, `MESSENGER_TRANSPORT_DSN` (Doctrine transport, `auto_setup=0`, so the messenger table comes from migrations), `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` (Web Push; only needed to send pushes).

## Architecture

The API logic is described in `docs/DESIGN.md`: authentication, authorization, the data model, endpoints, the bank connection and sync flows, and HAL links. Read it before changing resources, voters or the sync. Keep it up to date when that logic changes.

@docs/DESIGN.md

HTTP caching (ETag / 304, per-resource `Cache-Control`) is described in `docs/HTTP_CACHE.md`. Keep it up to date when that logic changes.

Automatic categorization (merchant key, categorization rules, split source) is described in `docs/AUTO_CATEGORIZATION.md`. Keep it up to date when that logic changes.

Notifications (inbox, `Notifier` and deduplication key, push subscriptions, settings, Web Push sending, daily scheduled task, periodic sync and the alerts that follow it) are described in `docs/NOTIFICATIONS.md`. Keep it up to date when that logic changes.

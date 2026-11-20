# Daily Budget API

Personal budget API: syncs bank accounts (GoCardless), categorizes transactions and tracks a shared monthly budget, with push alerts.

The web app that uses this API is [daily-budget-front](../daily-budget-front).

## Features

- **Bank accounts**: connect a bank through GoCardless Bank Account Data, then sync its accounts, balances and transactions in the background. Manual savings / investment accounts are supported too, and their balance can be corrected by hand.
- **Categorization**: split a transaction across categories, and remember a merchant so its next transactions are categorized automatically.
- **Budget**: one monthly budget shared by all users, split by expense category, with monthly statements and a savings dashboard.
- **Notifications**: an inbox and Web Push alerts for things like a low balance, a large transaction, income received, a budget almost or fully spent, an expiring bank connection, or the end of the month.

## Stack

- PHP 8.4, Symfony 8.1, API Platform 5
- Doctrine ORM: PostgreSQL in development, MariaDB in production
- Symfony Messenger for syncs, notifications and scheduled tasks
- OAuth2 / OIDC authentication: Bearer JWT from an external server
- Output formats: HAL (`application/hal+json`, the default), JSON and JSON-LD. Swagger UI is served at `/`.

## Getting started

```bash
composer install
cp .env .env.dev.local                  # then fill in the values below
docker compose up -d database           # PostgreSQL
bin/console doctrine:migrations:migrate
symfony serve                           # or: php -S localhost:8000 -t public
bin/console messenger:consume async push scheduler_default -vv   # background worker
```

### Configuration

| Variable | Purpose |
|---|---|
| `DATABASE_URL` | Database connection |
| `OAUTH2_CONFIGURATION_URL`, `OAUTH2_CLIENT_ID` | OIDC discovery URL and client id |
| `GOCARDLESS_BASE_URL`, `GOCARDLESS_CLIENT_ID`, `GOCARDLESS_CLIENT_SECRET` | GoCardless Bank Account Data |
| `MESSENGER_TRANSPORT_DSN` | Messenger transport (Doctrine) |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Web Push (only needed to send pushes) |

## Useful commands

```bash
bin/console debug:router                       # list the API routes
bin/console app:notifications:daily            # run the daily notification task now
bin/console app:notifications:test-push [user] # send a test push to a user's devices
```

## Deployment

The production stack is in `compose.prod.yaml`: front, API (FrankenPHP), Messenger worker, migrations and MariaDB. Its settings come from `stack.env`; `stack.env.example` lists the variables.

```bash
bin/release.sh v1.2.0   # build the API and front images and push them to the registry
```

See [CHANGELOG.md](CHANGELOG.md) for the release notes.

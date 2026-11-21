# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Sign-in goes through Authelia (auth.home.meunier.ovh). The API only checks the JWT access tokens: issuer, signature (any key of the JWKS, by kid), audience (`OAUTH2_AUDIENCE`, replaces `OAUTH2_CLIENT_ID`), and scopes from the `scp` claim.

### Removed
- The `/oauth/token` proxy and the OAuth login of Swagger UI: the front owns the whole OAuth flow. Swagger UI takes a pasted bearer token.

## [0.1.0] - 2026-11-20

First release.

### Added

#### Bank connection and sync
- Bank list from GoCardless Bank Account Data, cached for 24 hours.
- Account connections through GoCardless requisitions. When a user reconnects after an agreement expires, the existing accounts are reattached to the new connection.
- Bank accounts and their transactions are imported from the provider.
- History of the account syncs.
- The bank balance is stored at each sync.
- A periodic background sync of all accounts.

#### Accounts
- A display name that the user chooses (`PATCH /accounts/{id}`).
- Account types: checking, savings, investment. Only checking accounts count in the budget.
- Manual savings and investment accounts. Transfers categorized in a saving category create a matching transaction on the manual account.
- The balance of a manual account can be set by hand (`POST /accounts/{id}/balance`). The difference is booked as an adjustment transaction.

#### Transactions and categorization
- `GET /transactions`, with an `?uncategorized` filter.
- A system category catalog, user categories, and transactions that can be split across several categories.
- Categorization rules: a transaction's category can be remembered for its merchant and is then applied in the background to the merchant's other transactions.
- Splits set by a rule can be confirmed.
- A transaction can be hidden from the budget.

#### Budget and statements
- A monthly budget split by expense category.
- `GET /statements/{period}`: overview of the budget for a period.
- `GET /savings/{period}`: savings dashboard, with targets for the envelopes and category icons.
- `GET /user_actions`: the actions waiting for the user, by priority (connections to renew, transactions to categorize or review).

#### Notifications
- A notification inbox, push device subscriptions and notification settings.
- Web Push: the API sends the data and the front's service worker writes the text.
- A daily scheduled task: connection about to expire or expired, connection waiting for consent, end-of-month reminder.
- Alerts after each sync: low balance, large transaction, income received, budget almost spent or exceeded.

#### Sharing
- Accounts, connections, categories, rules and the budget are shared by all users, as in a household. There is a single budget.
- Only the owner of a resource (the user who created it) can delete it. Only the owner of a connection can configure, sync or renew it.
- A joint account connected by two users is only imported once.

#### Platform
- OAuth2 / OIDC authentication with Bearer JWT. Users are created on their first call.
- HAL output by default (JSON and JSON-LD are also available), and Swagger UI at `/`.
- HTTP cache: `304 Not Modified` on `If-None-Match`, and `Cache-Control` on resources that rarely change.
- Production Docker image (FrankenPHP), compose stack (API, worker, migrations, MariaDB, front) and release script.

### Fixed
- GoCardless: an expired token is refreshed, and a rate limit returns `503`.
- Sync: fewer required fields. Transactions without a `valueDate` are accepted. The creditor or debtor name is used as the description when there is none.
- Expired agreements are flagged and the accounts are reattached on reconnection.
- The `externalId` uniqueness check is done per account, like the database constraint. Before this fix, hiding a transaction could fail.
- compose.prod passes the VAPID public key to the front.

### Commits

- d660bc0 chore: remove useless docs
- 1f1d44d feat(accounts): set a manual account balance, booking the difference as an adjustment transaction
- f0e9162 feat(sharing): share data between all users, owner-only deletion
- 6996349 feat(transactions): hide a transaction from the budget
- ebf4a7b fix(deploy): pass the VAPID public key to the front in compose.prod
- 443c8b1 chore(migrations): merge develop migrations into Version20261115210000
- a9e0994 feat(notifications): add periodic sync and post-sync alerts (balance low, large transaction, income, budget)
- 9371ae9 feat(notifications): send Web Push on new notifications, text composed by the service worker
- b0efe30 feat(notifications): add notification inbox, push subscriptions, settings and daily scheduled notifications
- 664839a feat(categorization): add confirm flag to mark rule splits as confirmed instead of manual
- ada684a feat(http-cache): answer 304 on If-None-Match, add Cache-Control for rarely-changing resources
- f6b4d39 feat(categorization): add categorization rules applied asynchronously per account, merchant key and split source
- 889f3eb fix(sync): accept transactions without valueDate, use creditor/debtor name as description
- a0784b5 feat(savings): add GET /savings/{period} dashboard, envelope targets and category icons
- 2ab918e feat(account): add manual savings/investment accounts with mirror transactions from saving categories
- 499081f feat(account): add manual savings/investment accounts with mirror transactions from saving categories
- e93bb9a feat(account): add account type (checking, savings, investment), budget only counts checking accounts
- 485fe94 feat(account): store bank balance at each sync
- d75ffd2 feat(budget): add monthly budget per user and GET /statements/{period} overview
- f6d8662 chore(migrations): merge develop migrations into a single Version20261114150000
- 169b18a feat(transaction): add GET /transactions with ?uncategorized filter, scoped through account ownership
- b39569b feat(user-actions): add GET /user_actions endpoint with prioritized actions
- 16e3847 feat(account): add custom display name editable via PATCH /accounts/{id}
- f3352e5 fix(account-connection): flag expired agreements as EX and reattach accounts on reconnection
- 0af1980 feat(account-sync): add history for account sync
- 762e48d feat(categorization): add category spilts
- 8eca392 feat(category): add system catalog, user categories and transaction categorization
- 92fc562 fix(transaction-sync): reduce mandatories fields
- f4ea45b chore(deploy): add production Docker image, compose stack and release script
- 6174eb8 fix(gocardless): cache bank list 24h, return 503 on rate limit, refresh expired token
- 4c5d2ca feat(transactions): add transactions from provider
- ba375ee feat(account): add account resource
- f138514 feat(bank): add bank list from gocardless
- 22985e2 feat(account-connection): add gocardless requisition support (account connection)
- c376683 chore(init): initial commit

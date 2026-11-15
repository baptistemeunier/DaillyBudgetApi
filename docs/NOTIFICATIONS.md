# Notifications (API)

La proposition complète (catalogue, anti-spam, Web Push, front) est dans `daily-budget-front/docs/NOTIFICATIONS.md`. Ce document décrit ce qui est **implémenté côté API** : l'étape 1, c'est-à-dire le socle, la boîte de notifications et la tâche quotidienne, ainsi que les préférences (`NotificationSettings`), stockées mais pas encore utilisées.

Une notification est un **événement daté** (« ça vient d'arriver »). Une `UserAction` est un **état**, calculé à la demande. Les deux coexistent.

## Types (`App\Enum\NotificationType`)

| Type | Sujet | Déclencheur | Clé de dédoublonnage | `data` |
|---|---|---|---|---|
| `CONNECTION_EXPIRING` | `accountConnection` | Tâche quotidienne : l'accès expire dans 7 jours ou moins, puis dans 1 jour ou moins | `CONNECTION_EXPIRING_{connection}_7D`, `…_1D` | `bankId`, `expiresAt`, `daysLeft` |
| `CONNECTION_EXPIRED` | `accountConnection` | Tâche quotidienne : accès expiré ou suspendu | `CONNECTION_EXPIRED_{connection}` | `bankId`, `expiresAt` |
| `CONNECTION_TO_CONFIGURE` | `accountConnection` | Tâche quotidienne : connexion toujours sans consentement 24 h après sa création | `CONNECTION_TO_CONFIGURE_{connection}` | `bankId` |
| `MONTH_CLOSING` | aucun | Tâche quotidienne : il reste 1 à 5 jours dans le mois (UTC) et des transactions du mois à catégoriser | `MONTH_CLOSING_{user}_{YYYY-MM}` | `count`, `month`, `daysLeft` |

L'état d'une connexion est calculé par `App\Service\ConnectionStatusResolver`, la même règle que `GET /user_actions`.

La fenêtre de `MONTH_CLOSING` (1 à 5 jours) rattrape un passage manqué le lendemain. La clé mensuelle garantit un seul envoi.

`CONNECTION_EXPIRED` n'est pas encore envoyée quand la banque refuse une sync : ce sera fait à l'étape 3 (`AccountAlertsRequest`). Pour l'instant, la tâche du lendemain la détecte.

## Modèle

### `Notification`

| Champ | Exposé | Note |
|---|---|---|
| `id` (uuid v7), `createdAt`, `updatedAt` | oui | |
| `owner` | non | `onDelete: CASCADE` |
| `type` | oui | `NotificationType` |
| `account`, `accountConnection`, `transaction`, `category` | oui, en liens HAL | Au plus un renseigné : la signature du `Notifier` l'impose. `onDelete: CASCADE` |
| `data` | oui | json, paramètres du texte |
| `read` | oui | Calculé à partir de `readAt`, modifiable par `PATCH` |
| `readAt` | oui | Date de la première lecture |
| `dedupKey` | non | `UNIQUE(owner_id, dedup_key)` |
| `pushedAt` | non | Prévu pour l'étape 2 (Web Push) |

Le texte n'est pas stocké : le front le compose avec son i18n, à partir de `type` et `data`.

### `PushSubscription`

Un abonnement Web Push par appareil ou navigateur. Il est déjà exposé pour que le front puisse s'abonner, mais **rien n'est envoyé** avant l'étape 2.

| Champ | Exposé | Note |
|---|---|---|
| `id` | oui | uuid **v5** de `endpoint` (espace de noms URL), calculé par le client |
| `owner` | non | `onDelete: CASCADE` |
| `endpoint` | oui | Pas d'index unique : l'id, qui en dérive, l'est déjà |
| `p256dh`, `auth` | non (écriture seule, via `keys`) | |
| `locale` | oui | `fr` ou `en` |
| `userAgent` | oui | Pris dans l'en-tête de la requête `PUT` |
| `createdAt`, `updatedAt`, `lastSuccessAt` | oui | |

### `NotificationSettings`

Les préférences : une ressource **par utilisateur**, sur le modèle de `Budget` (la collection contient 0 ou 1 élément). Elle est créée au premier réglage modifié. Elles sont déjà enregistrées, mais **ne servent pas encore** : elles seront lues par l'envoi des pushes (étape 2) et par les alertes (étape 3).

| Champ | Note |
|---|---|
| `push` | Lecture : chaque type → `true`/`false`, avec le choix de l'utilisateur ou, à défaut, `NotificationType::defaultPush()`. Écriture : seuls les types envoyés changent (fusion). Un type inconnu ou une valeur non booléenne donne une 422 |
| `largeTransactionThreshold` | Décimal, `200.00` par défaut. Pour `LARGE_TRANSACTION` (étape 3) |
| `hideAmounts` | `false` par défaut. Garde les montants hors du texte des pushes |

Stockage : `push` en json, avec seulement les types modifiés. Les deux autres sont des colonnes typées. La notification dans l'app est toujours créée : la préférence ne coupe que le push. Sans ressource, `NotificationSettingsRepository::findOrDefault()` renvoie les valeurs par défaut.

### Seuil de solde bas (`Account.lowBalanceThreshold`)

Il n'est pas dans `NotificationSettings` : il est propre à chaque compte. C'est un décimal nullable, éventuellement négatif (découvert). `null`, la valeur par défaut, veut dire que le compte n'envoie jamais `BALANCE_LOW`. Il n'y a ni valeur globale ni valeur par défaut. Il ne sert qu'aux comptes courants. Il est enregistré dès maintenant, mais `BALANCE_LOW` n'arrive qu'à l'étape 3.

## Endpoints

Ressources et verbes HTTP, sans endpoint d'action. Toutes sont limitées à l'owner (`CurrentUserExtension` et voters `NotificationVoter`, `PushSubscriptionVoter`, `NotificationSettingsVoter`).

**Aucun scope OAuth** n'est demandé : tout utilisateur connecté a sa boîte de notifications et ses appareils.

| Requête | Rôle |
|---|---|
| `GET /notifications` | Boîte de notifications, la plus récente en premier, paginée |
| `GET /notifications?read=false` | Non lues (`read=true` : lues). Filtre `App\Doctrine\Filter\ReadFilter` |
| `GET /notifications?read=false&itemsPerPage=1` | Pastille de la cloche : lire `totalItems` |
| `GET /notifications/{id}` | Une notification |
| `PATCH /notifications/{id}` `{ "read": true }` | Marque comme lue (`readAt` garde la première date). `false` : non lue |
| `DELETE /notifications/{id}` | Supprime de la boîte |
| `GET /push_subscriptions` | Appareils abonnés |
| `PUT /push_subscriptions/{id}` `{ endpoint, keys: { p256dh, auth }, locale }` | Crée (201) ou remplace (200) l'abonnement de l'appareil |
| `DELETE /push_subscriptions/{id}` | Désabonne l'appareil |
| `GET /notification_settings` | Les préférences de l'utilisateur : 0 ou 1 élément |
| `POST /notification_settings` | Création au premier réglage modifié (422 si elles existent déjà) |
| `PATCH /accounts/{id}` `{ "lowBalanceThreshold": "150.00" }` | Seuil de solde bas du compte (`null` pour couper) |
| `PATCH /notification_settings/{id}` | Modification, par exemple `{ "push": { "MONTH_CLOSING": false } }` |

Les notifications n'ont pas de `POST` : seul le serveur en crée.

**`PUT /push_subscriptions/{id}`** (`PushSubscriptionProcessor`) :
- renvoie une 422 si `id` n'est pas `Uuid::v5(NAMESPACE_URL, endpoint)` ;
- si l'appareil était abonné par un autre utilisateur (même navigateur, autre compte), l'abonnement passe à l'utilisateur courant ;
- persiste lui-même l'entité, sans le processor Doctrine. Le « standard PUT » de celui-ci remettrait à zéro les champs non envoyés (`owner`, `createdAt`).

## Création : `App\Notification\Notifier`

C'est le seul point d'entrée : `notify(owner, type, subject, data, dedupKey)`.
- Il insère la ligne avec DBAL et ignore la violation de `UNIQUE(owner_id, dedup_key)`. C'est l'équivalent portable de `ON CONFLICT DO NOTHING`, et le gestionnaire d'entités de l'appelant reste ouvert.
- Il renvoie `false` si la notification existait déjà. Rejouer un handler ou la tâche ne crée donc jamais de doublon.
- La clé de dédoublonnage vient de `NotificationType::dedupKey(uuid, ?période)`, au format `{TYPE}_{uuid}[_{période}]`.
- À l'étape 2, il enverra ici `SendPushNotification` après l'insertion.

## Tâche quotidienne

```
Scheduler (MainSchedule, 8 h UTC) ─▶ DailyNotificationsMessage ─▶ DailyNotificationsMessageHandler
                                                                     ├─ purge des notifications lues depuis plus de 90 jours
                                                                     └─ UserNotificationsRequest(user) sur async, par utilisateur
                                                                           ayant une connexion ou un compte non supprimé
                                                                        └─▶ UserNotificationsRequestHandler ─▶ Notifier
```

- `App\Scheduler\MainSchedule` (`#[AsSchedule]`, nom `default`) : `stateful()` sur `cache.app`, `lock()` (store `LOCK_DSN`, `flock` par défaut).
- Le worker consomme le transport du scheduler : `messenger:consume async scheduler_default` (voir `compose.prod.yaml`).
- Le cache du worker n'est pas persistant entre deux conteneurs : un passage manqué pendant un redéploiement n'est pas rattrapé. Les fenêtres de `CONNECTION_EXPIRING` (1 à 7 jours) et de `MONTH_CLOSING` (1 à 5 jours) le tolèrent.
- `bin/console app:notifications:daily` lance la tâche tout de suite. La purge est faite sur place, puis il faut consommer `async`.

## Pas encore implémenté

- **Étape 2, Web Push** : clés VAPID, `minishlink/web-push`, `SendPushNotification` (qui lira `NotificationSettings`), `pushedAt` et `lastSuccessAt`, traductions `translations/notifications.{fr,en}.yaml`.
- **Étape 3, sync périodique et alertes** : `SyncAllAccountsMessage`, `AccountAlertsRequest`, `BALANCE_LOW` (le seuil `Account.lowBalanceThreshold` existe déjà), `LARGE_TRANSACTION`, `INCOME_RECEIVED`, `BUDGET_ALMOST_EXCEEDED`, `BUDGET_EXCEEDED`, regroupement par sync.

# Notifications (API)

La proposition complète (catalogue, anti-spam, Web Push, front) est dans `daily-budget-front/docs/NOTIFICATIONS.md`. Ce document décrit ce qui est **implémenté côté API** : les étapes 1 à 3 : le socle, la boîte de notifications, la tâche quotidienne, les préférences (`NotificationSettings`), l'envoi Web Push, la sync périodique et les alertes qui la suivent.

Une notification est un **événement daté** (« ça vient d'arriver »). Une `UserAction` est un **état**, calculé à la demande. Les deux coexistent.

## Types (`App\Enum\NotificationType`)

| Type | Sujet | Déclencheur | Clé de dédoublonnage | `data` |
|---|---|---|---|---|
| `CONNECTION_EXPIRING` | `accountConnection` | Tâche quotidienne : l'accès expire dans 7 jours ou moins, puis dans 1 jour ou moins | `CONNECTION_EXPIRING_{connection}_7D`, `…_1D` | `bankId`, `bankName`, `expiresAt`, `daysLeft` |
| `CONNECTION_EXPIRED` | `accountConnection` | Tâche quotidienne : accès expiré ou suspendu. Ou tout de suite, quand la banque refuse une sync (`AccountAlertsRequestHandler`) | `CONNECTION_EXPIRED_{connection}` | `bankId`, `bankName`, `expiresAt` |
| `CONNECTION_TO_CONFIGURE` | `accountConnection` | Tâche quotidienne : connexion toujours sans consentement 24 h après sa création | `CONNECTION_TO_CONFIGURE_{connection}` | `bankId`, `bankName` |
| `MONTH_CLOSING` | aucun | Tâche quotidienne : il reste 1 à 5 jours dans le mois (UTC) et des transactions du mois à catégoriser | `MONTH_CLOSING_{user}_{YYYY-MM}` | `count`, `month`, `daysLeft` |
| `BALANCE_LOW` | `account` | Après une sync : le solde d'un compte courant passe sous `lowBalanceThreshold` | `BALANCE_LOW_{account}_{YYYY-MM-DD}` | `accountId`, `accountName`, `balance`, `threshold`, `currency` |
| `LARGE_TRANSACTION` | `transaction`, ou `account` si regroupées | Après une sync : nouveau débit d'au moins `largeTransactionThreshold` | `LARGE_TRANSACTION_{transaction}`, regroupées `LARGE_TRANSACTION_{account}_{sync}` | `accountId`, `accountName`, `currency`, puis `amount`, `description`, `bookingAt`, ou, regroupées, `count`, `total` |
| `INCOME_RECEIVED` | `transaction`, ou `account` si regroupées | Après la catégorisation qui suit une sync : nouveau crédit dans une catégorie de type `income`. Pas de push par défaut | `INCOME_RECEIVED_{transaction}`, regroupées `INCOME_RECEIVED_{account}_{date et heure}` | comme `LARGE_TRANSACTION`, + `categoryCode`, `categoryName` |
| `BUDGET_ALMOST_EXCEEDED` | `category` (racine) | Après la catégorisation qui suit une sync : le dépensé du mois atteint 80 % de la ligne de budget | `BUDGET_ALMOST_EXCEEDED_{category}_{YYYY-MM}` | `month`, `spent`, `budgeted`, `percent`, `currency`, `categoryCode`, `categoryName` |
| `BUDGET_EXCEEDED` | `category` (racine) | Idem, le dépensé dépasse la ligne de budget | `BUDGET_EXCEEDED_{category}_{YYYY-MM}` | idem |

`bankName` est résolu à la création, avec la liste des banques en cache (`App\Service\BankNameResolver`). Il vaut `null` si la liste est indisponible : le front affiche alors `bankId`. Il est stocké parce que le service worker rédige le push sans appeler l'API.

L'état d'une connexion est calculé par `App\Service\ConnectionStatusResolver`, la même règle que `GET /user_actions`.

La fenêtre de `MONTH_CLOSING` (1 à 5 jours) rattrape un passage manqué le lendemain. La clé mensuelle garantit un seul envoi.

Les montants sont des décimaux en chaîne, signés comme les transactions (débit négatif). `categoryCode` est le code d'une catégorie système (`food`, `income.salary`…), `categoryName` le nom d'une catégorie de l'utilisateur.

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
| `pushedAt` | non | Date du premier push accepté par un appareil. Null tant qu'aucun n'est parti, ou si le type est désactivé |

Le texte n'est pas stocké : le front le compose avec son i18n, à partir de `type` et `data`.

### `PushSubscription`

Un abonnement Web Push par appareil ou navigateur. Voir [Web Push](#web-push) pour l'envoi.

| Champ | Exposé | Note |
|---|---|---|
| `id` | oui | uuid **v5** de `endpoint` (espace de noms URL), calculé par le client |
| `owner` | non | `onDelete: CASCADE` |
| `endpoint` | oui | Pas d'index unique : l'id, qui en dérive, l'est déjà |
| `p256dh`, `auth` | non (écriture seule, via `keys`) | |
| `locale` | oui | `fr` ou `en` |
| `userAgent` | oui | Pris dans l'en-tête de la requête `PUT` |
| `createdAt`, `updatedAt` | oui | |
| `lastSuccessAt` | oui | Dernier push accepté par le service de push |

### `NotificationSettings`

Les préférences : une ressource **par utilisateur**, sur le modèle de `Budget` (la collection contient 0 ou 1 élément). Elle est créée au premier réglage modifié. `push` et `hideAmounts` sont lus par l'envoi des pushes. `largeTransactionThreshold` sert à `LARGE_TRANSACTION`.

| Champ | Note |
|---|---|
| `push` | Lecture : chaque type → `true`/`false`, avec le choix de l'utilisateur ou, à défaut, `NotificationType::defaultPush()`. Écriture : seuls les types envoyés changent (fusion). Un type inconnu ou une valeur non booléenne donne une 422 |
| `largeTransactionThreshold` | Décimal, `200.00` par défaut. Seuil de `LARGE_TRANSACTION` |
| `hideAmounts` | `false` par défaut. Transmis dans le push : le service worker garde alors les montants hors du texte |

Stockage : `push` en json, avec seulement les types modifiés. Les deux autres sont des colonnes typées. La notification dans l'app est toujours créée : la préférence ne coupe que le push. Sans ressource, `NotificationSettingsRepository::findOrDefault()` renvoie les valeurs par défaut.

### Seuil de solde bas (`Account.lowBalanceThreshold`)

Il n'est pas dans `NotificationSettings` : il est propre à chaque compte. C'est un décimal nullable, éventuellement négatif (découvert). `null`, la valeur par défaut, veut dire que le compte n'envoie jamais `BALANCE_LOW`. Il n'y a ni valeur globale ni valeur par défaut. Il ne sert qu'aux comptes courants. Voir [Sync périodique et alertes](#sync-périodique-et-alertes).

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
- Après l'insertion, il envoie `SendPushNotification(id)` sur `async` (voir [Web Push](#web-push)).

## Tâche quotidienne

```
Scheduler (MainSchedule, 8 h UTC) ─▶ DailyNotificationsMessage ─▶ DailyNotificationsMessageHandler
                                                                     ├─ purge des notifications lues depuis plus de 90 jours
                                                                     └─ UserNotificationsRequest(user) sur async, par utilisateur
                                                                           ayant une connexion ou un compte non supprimé
                                                                        └─▶ UserNotificationsRequestHandler ─▶ Notifier
```

- `App\Scheduler\MainSchedule` (`#[AsSchedule]`, nom `default`) : `stateful()` sur `cache.app`, `lock()` (store `LOCK_DSN`, `flock` par défaut).
- Le worker consomme le transport du scheduler : `messenger:consume async push scheduler_default` (voir `compose.prod.yaml`).
- Le cache du worker n'est pas persistant entre deux conteneurs : un passage manqué pendant un redéploiement n'est pas rattrapé. Les fenêtres de `CONNECTION_EXPIRING` (1 à 7 jours) et de `MONTH_CLOSING` (1 à 5 jours) le tolèrent.
- `bin/console app:notifications:daily` lance la tâche tout de suite. La purge est faite sur place, puis il faut consommer `async`.

## Web Push

```
Notifier ─▶ SendPushNotification (async) ─▶ SendPushNotificationHandler
                                              ├─ préférence du type désactivée → stop (la notification reste dans l'app)
                                              └─ DeliverPush(notification, abonnement) par appareil, sur le transport « push »
                                                   └─▶ DeliverPushHandler ─▶ PushMessageFactory + WebPushSender
```

- **Un message par appareil** (`DeliverPush`), sur un transport à part, `push` (`queue_name=push`, même table messenger). Il a sa propre stratégie de relance : 3 essais, à 1 min, 4 min puis 16 min. Un appareil qui a déjà reçu le push ne le reçoit pas deux fois.
- **Résultat de l'envoi** :
  - accepté : `lastSuccessAt` sur l'abonnement, et `pushedAt` sur la notification si c'est le premier ;
  - 404 ou 410 : l'abonnement est mort, il est supprimé ;
  - 5xx, 429 ou erreur réseau : une exception, relancée par le transport ;
  - autre 4xx : un log, sans relance.
- **VAPID** : `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` (`mailto:` ou l'URL du site). Les clés se génèrent une fois avec `Minishlink\WebPush\VAPID::createVapidKeys()`. La clé publique n'est pas secrète : le front la reçoit dans `NUXT_PUBLIC_VAPID_PUBLIC_KEY`, sans endpoint. Sans clés, seul l'envoi échoue. L'extension `gmp` accélère le chiffrement.
- **Options** : `TTL` de 24 h et `urgency: normal`. L'en-tête `Topic` est un hash du type et du sujet, 32 caractères base64url au plus : un push pas encore reçu (appareil hors ligne) est remplacé par le suivant au lieu de s'empiler.

- **Test** : `bin/console app:notifications:test-push` liste les appareils abonnés. Avec un utilisateur (username ou uuid), ou `--device=<id>`, la commande envoie tout de suite un push de test, sans passer par la file. Elle affiche la réponse du service de push pour chaque appareil, sans rien créer ni modifier en base. Deux modes :
  - sans option, un push de type `TEST`, avec un `title` et un `body` déjà rédigés que le service worker affiche tels quels. Il vérifie la livraison seule. Le texte se règle avec `--title` et `--body`, la page ouverte avec `--url` ;
  - avec `--type=<NotificationType>` (par exemple `--type=MONTH_CLOSING`), le payload d'une vraie notification de ce type, avec des données d'exemple, dans la `locale` de chaque appareil. Il vérifie aussi le texte rédigé par le service worker. `--hide-amounts` simule l'option `hideAmounts`.

### Contenu (`PushMessageFactory`)

```json
{ "type": "MONTH_CLOSING", "data": { "count": 8, "month": "2026-11", "daysLeft": 5 }, "locale": "fr", "hideAmounts": false, "url": "/transactions/to-categorize?notification=0193…", "tag": "MONTH_CLOSING:2026-11", "notification": "/notifications/0193…" }
```

- **Le texte n'est pas rédigé par l'API.** Le service worker le compose avec l'i18n du front, à partir de `type` et `data`, avec les mêmes clés que la boîte de notifications (`notifications.<TYPE>.title|body`). Les textes vivent ainsi à un seul endroit. Il doit prévoir un texte générique pour un type qu'il ne connaît pas (ancien service worker encore en cache).
- **`locale`** : la langue de l'abonnement (`fr` par défaut), dans laquelle le service worker rédige le push.
- **`data`** : les mêmes données que la notification (`GET /notifications/{id}`). Les dates et les mois sont formatés par le service worker (`Intl`).
- **`hideAmounts`** : le service worker garde les montants (`amount`, `total`, `balance`, `spent`…) hors du titre et du corps, qui s'affichent sur l'écran verrouillé.
- **`url`** : la page ouverte au tap, avec `?notification={id}` pour que le front marque la notification comme lue :
  - `/profile/connections` pour `CONNECTION_EXPIRING` et `CONNECTION_EXPIRED` ;
  - le lien de consentement de la banque pour `CONNECTION_TO_CONFIGURE`, une page externe, donc sans marquage ;
  - `/transactions/to-categorize` pour `MONTH_CLOSING` ;
  - `/accounts/{account}` pour `BALANCE_LOW` et les notifications regroupées, `/accounts/{account}?transaction={id}` pour une seule transaction ;
  - `/budget` pour `BUDGET_*`.
- **`tag`** : `{TYPE}:{sujet}` (`{TYPE}:{mois}` pour `MONTH_CLOSING`). Le service worker le passe à `showNotification`, qui remplace la notification affichée du même sujet.

## Sync périodique et alertes

```
Scheduler (6 h et 18 h UTC) ─▶ SyncAllAccountsMessage ─▶ AccountSync + AccountSyncRequest par compte synchronisable
AccountSyncRequestHandler
  ├─ succès ─▶ AccountAlertsRequest(sync, solde d'avant)              ─▶ BALANCE_LOW, LARGE_TRANSACTION
  │        └─▶ AccountCategorizationRequest(compte, alerts: true)     ─▶ règles, puis INCOME_RECEIVED, BUDGET_*
  └─ refus de la banque (ACCESS_EXPIRED) ─▶ AccountAlertsRequest       ─▶ CONNECTION_EXPIRED
```

- **Sync périodique** (`SyncAllAccountsMessageHandler`) : deux fois par jour, pour chaque compte synchronisable (`AccountRepository::findSyncable()`) :
  - un compte bancaire non supprimé, pas `EXPIRED` chez la banque ;
  - sur une connexion non supprimée, `LINKED`, dont l'accès n'a pas expiré ;
  - sans sync déjà en attente ou en cours.

  Le quota GoCardless (4 syncs par compte sur 24 h) reste appliqué par `AccountSyncRequestHandler` : deux syncs planifiées laissent de la place aux syncs manuelles.
- **Deux handlers après la sync** :
  - `AccountAlertsRequestHandler` reçoit le solde d'avant la sync dans le message (pas de colonne en base). Il gère les alertes qui n'ont pas besoin des catégories.
  - Les alertes sur les catégories suivent la catégorisation, pour voir les catégories posées par les règles. `AccountCategorizationRequest` a un booléen `alerts`. Il vaut `false` quand la catégorisation suit une modification des règles : pas d'alerte dans ce cas.
- **Première sync d'un compte** : aucune alerte. Elle importe des mois d'historique.
- **Nouvelles transactions** : celles créées pendant la sync (`createdAt`), comptabilisées depuis 7 jours au plus (`TransactionNotifier::NEW_TRANSACTION_DAYS`). La sync remonte 5 jours en arrière et peut ramener des transactions anciennes.
- **`BALANCE_LOW`** : seulement au franchissement du seuil (solde d'avant ≥ seuil > solde d'après), sur un compte courant qui a un `lowBalanceThreshold`. Un solde qui reste bas n'envoie rien. La date dans la clé limite à une notification par jour.
- **Regroupement** (`TransactionNotifier`) : jusqu'à 3 transactions trouvées ensemble, une notification par transaction. Au-delà, une seule notification regroupée sur le compte (`count`, `total`).
- **`INCOME_RECEIVED`** : crédits que les règles viennent de catégoriser dans une catégorie de type `income` (ou sous-catégorie). Une transaction catégorisée à la main n'envoie rien.
- **`BUDGET_*`** (`CategoryAlerts`), seulement pour un compte courant et si l'utilisateur a un budget :
  - les catégories racines `expense` touchées par les transactions du mois que les règles viennent de catégoriser ;
  - le dépensé du mois (UTC, même requête que le relevé, `sumSplitsByRootCategory`) est comparé à la `BudgetLine` :
    - au-delà de 100 % : `BUDGET_EXCEEDED` ;
    - à partir de 80 % : `BUDGET_ALMOST_EXCEEDED`, sauf si `BUDGET_EXCEEDED` a déjà été envoyée ce mois-ci pour la catégorie (dans le cas d'un remboursement, par exemple).
  - Chacune part au plus une fois par catégorie et par mois.
  - Un dépassement causé par une catégorisation manuelle n'envoie rien sur le moment : il est signalé à la sync suivante s'il dure toujours et que de nouvelles transactions touchent la catégorie.

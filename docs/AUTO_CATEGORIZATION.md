# Catégorisation automatique

Les transactions synchronisées arrivent sans catégorie. Pour éviter à l'utilisateur de tout catégoriser à la main, des **règles personnelles** posent la catégorie à sa place. L'utilisateur garde la main : un split posé par une règle est marqué comme tel, et il le confirme ou le corrige.

Pour l'instant, seules les règles existent (voir « Évolutions »).

## Clé marchand

`Transaction.merchantKey` est la description réduite aux mots qui nomment le marchand (`App\Util\MerchantKey`). Elle est recalculée à chaque `setDescription()`.

- La description vient de `App\Util\BankDescription` : le nom de la contrepartie (`debtorName` pour un crédit, `creditorName` pour un débit), sinon les 50 premiers caractères du libellé de remise.
- Normalisation : translittération ASCII (`Transliterator` d'intl, car `iconv` ne translittère pas sur musl/Alpine), majuscules, découpage en mots sur tout ce qui n'est pas `A-Z0-9`, retrait des mots contenant un chiffre (dates, n° de carte, références) et des mots bancaires en tête (`CB`, `CARTE`, `PRLV`, `SEPA`, `VIR`, `PAIEMENT`, `ACHAT`…). Le résultat fait 100 caractères au maximum, et vaut `null` s'il ne reste aucun mot.
- Exemple : `CB CARREFOUR MARKET 12/11 X4521` → `CARREFOUR MARKET`.

`bin/console app:transactions:merchant-keys [--apply-rules]` reconstruit la description (depuis `rawData`, pour les comptes synchronisés) et la clé de toutes les transactions. Avec `--apply-rules`, il applique aussi les règles. La commande est idempotente.

## Règles

`CategorizationRule` : `owner`, `pattern`, `direction` (`debit` | `credit` | `null` = les deux), `category`. Une seule règle par `(owner, pattern, direction)`.

- **Correspondance** : le `pattern` (normalisé comme une clé, sans retrait des mots bancaires) apparaît dans la clé marchand **en mots entiers** (`CARREFOUR` correspond à `CARREFOUR MARKET`, pas à `CARREFOURS`). La direction et le type de la catégorie doivent accepter le signe du montant.
- **Priorité** : le pattern le plus long l'emporte, puis la règle qui a une direction. Une règle précise (`AMAZON PRIME`) passe donc avant une règle large (`AMAZON`).
- **Catégorie** : une sous-catégorie visible par l'utilisateur, non supprimée, et **jamais d'épargne**, car le miroir de `SavingMirrorLinker` demande un compte manuel (`ValidCategorizationRule`).
- **Périmètre** : uniquement les transactions des comptes synchronisés actifs de l'owner. Les comptes manuels (miroirs, solde initial) ne sont jamais touchés.

### Quand une règle s'applique

`App\Service\TransactionCategorizer` :

| Moment | Ce qui est catégorisé |
|---|---|
| Après une sync réussie : `AccountSyncRequestHandler` envoie `AccountCategorizationRequest` (async) | les transactions du compte sans split `manual` (`AccountCategorizationRequestHandler`) |
| POST / PATCH `/categorization_rules` | un `AccountCategorizationRequest` par compte synchronisé actif de l'utilisateur (async) |
| PATCH `/transactions/{id}` avec `rememberCategory: true` | idem, une fois la règle créée ou mise à jour |
| `app:transactions:merchant-keys --apply-rules` | toutes les transactions |

Une transaction est catégorisée seulement si **aucun de ses splits n'est `manual`**. Elle reçoit alors un unique split du montant total, avec `source: rule` et un lien vers la règle. Un split posé par une règle peut être remplacé par une meilleure règle. Toutes les règles de l'owner sont toujours comparées : la plus précise garde ses transactions. Après un changement de règle, le résultat apparaît dès que le worker a traité les messages (`messenger:consume async`).

Supprimer une règle conserve les splits qu'elle a posés (leur `rule` passe à `null`, ils restent `source: rule`).

### Origine d'un split

`TransactionSplit.source` (`manual` | `rule`, lecture seule) et `_links.rule`.

Le PATCH (`TransactionProcessor`) recrée tous les splits envoyés, donc tout split envoyé par le client devient `manual` :

- **confirmer** = renvoyer le même split ;
- **corriger** = en envoyer un autre.

## API

| Endpoint | Rôle |
|---|---|
| `GET /categorization_rules`, `GET /categorization_rules/{id}` | `category:read`, owner uniquement (`CurrentUserExtension`, `CategorizationRuleVoter`) |
| `POST /categorization_rules`, `PATCH`, `DELETE /categorization_rules/{id}` | `category:write`, owner uniquement. Doublon `(pattern, direction)` → 422 |
| `PATCH /transactions/{id}` (DTO `UpdateTransaction`) | `splits` remplace tous les splits (`null` = inchangés, `[]` = non catégorisée). Avec `rememberCategory: true`, il faut un unique split du montant total avec une catégorie, sinon 422 : la règle `merchantKey + sens → catégorie` est créée ou mise à jour |
| `GET /transactions?autoCategorized=true` | transactions avec un split `source: rule`, à revoir |
| `GET /user_actions` | `TRANSACTIONS_TO_REVIEW` (priorité 5) avec le nombre de transactions à revoir |

UX côté client :

- une case « Toujours classer ainsi » sur l'écran de catégorisation ;
- un écran « Règles » (liste, édition, suppression) ;
- une liste « À vérifier » avec un bouton « Confirmer » qui renvoie le split.

## Évolutions

- **Apprentissage par l'historique** : après les règles, si les dernières transactions mono-split catégorisées à la main pour la même clé et le même sens (au moins 2) ont toutes la même catégorie, l'appliquer avec `source: history`. Zéro configuration pour l'utilisateur. Sources à ajouter : `SplitSource::HISTORY`, `TransactionRepository::findHistoryCategories()`.
- **Règles globales** (catalogue des grands marchands, `owner = null`) gérées par un admin. Elles s'appliqueraient après les règles et l'historique de l'utilisateur.
- **Catégories d'épargne** : une règle pourrait porter le compte manuel de destination du miroir.

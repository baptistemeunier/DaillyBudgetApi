# Cache HTTP (ETag)

Le but : que le front ne retélécharge pas les ressources qui changent peu.

## Côté API

- **ETag** : API Platform pose un `ETag` sur toute réponse GET réussie (`AddHeadersProcessor`, hash xxh3 du corps). Il est actif par défaut.
- **304** : `App\EventListener\NotModifiedListener` compare `If-None-Match` à l'ETag. S'ils correspondent, il renvoie un `304 Not Modified` sans corps. La réponse est quand même calculée, donc on économise de la bande passante et du parsing côté client, pas du travail serveur.
- **Toujours `private`** (`defaults.cache_headers.public: false`) : les réponses dépendent de l'utilisateur. Aucun cache partagé (proxy, CDN) ne doit les garder. `Vary: Content-Type, Authorization, Origin`.

| Ressource | `Cache-Control` | Raison |
|---|---|---|
| `Bank` | `max-age=3600, private` | Liste déjà mise en cache 24 h côté serveur (`BankProvider::CACHE_TTL`) |
| `Category`, `Budget`, `CategorizationRule` | `max-age=0, must-revalidate, private` | Changent peu, mais doivent être à jour juste après un PATCH : revalidation systématique via l'ETag |
| Le reste (transactions, relevés, syncs, actions) | ETag seul | Volatiles |

Pour rendre une autre ressource cacheable, ajoutez `cacheHeaders` sur son `#[ApiResource]`. Ce réglage ne s'applique qu'aux méthodes GET et HEAD.

## Côté front

- **Le cache HTTP du navigateur suffit.** Avec `max-age=0, must-revalidate` + `ETag`, `fetch()` envoie `If-None-Match` tout seul et réutilise le corps en cache sur un 304. Le code ne voit qu'un 200.
- **localStorage** ne sert que pour l'affichage instantané ou le hors-ligne. Stocker `{etag, body}` par URL, envoyer `If-None-Match: <etag>` à la main, et réutiliser `body` sur un 304.
- **Cross-origin** : si le front n'est pas servi depuis la même origine, il faut exposer l'en-tête (`Access-Control-Expose-Headers: ETag`) pour pouvoir le lire en JS. Aucun bundle CORS n'est installé pour l'instant.

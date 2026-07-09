# File list caching plan

## Doel

De file/folder lijst sneller en stabieler maken bij refresh, navigeren, sorteren en infinite scroll. De cache moet vooral read-heavy folder views versnellen zonder onbetrouwbare data te tonen na upload, delete, restore of folder-mutaties.

Dit plan implementeert nog geen cache. De enige directe codewijziging naast dit document is de paginatie-limit verhogen zodat volle folders minder vaak direct na page load extra pagina's hoeven bij te laden.

## Huidige situatie

- `FileController::myFiles()` queryt elke folder view direct uit de database.
- De eerste pagina bevatte 10 items; bij volle folders triggert de frontend snel extra `loadMore` requests, waardoor de lijst zichtbaar inspringt.
- Uploads, deletes, restores en folder creates reloaden delen van de Inertia props.
- Er is al `StorageUserService` cache, maar nog geen cache voor file listings.

## Voorstel

Gebruik Redis als Laravel cache store met tagged cache.

Waarom Redis/tags:

- Folder listings zijn goed te taggen per user en per folder.
- Bij mutaties kunnen we gericht invalidaten zonder de hele cache leeg te gooien.
- Laravel ondersteunt cache tags met Redis/Memcached stores.
- Paginatie, sortering en limit kunnen veilig onderdeel zijn van de cache key.

## Configuratie

Zet in `.env`:

```env
CACHE_STORE=redis
REDIS_CLIENT=phpredis
```

Controleer dat Redis draait in lokale/dev/prod omgeving en dat de gekozen Laravel cache driver tags ondersteunt. De database/file cache stores zijn hiervoor niet geschikt.

## Cache scope

Cache alleen de read response voor folder listings:

- `myFiles`
- eventueel later `trash`

Niet cachen:

- download responses
- upload plan responses
- presigned URLs
- user-specific authorization checks buiten de listing query

## Cache keys

Gebruik tags:

- `user:{user_id}:files`
- `user:{user_id}:folder:{folder_id}`

Gebruik key:

```txt
files:list:user:{user_id}:folder:{folder_id}:page:{page}:limit:{limit}:sort:{sortBy}:{sortDirection}
```

Voor root folder gebruikt `folder_id` gewoon de root folder id.

## Data om te cachen

Cache niet de ruwe Eloquent query builder. Cache de uiteindelijke paginated resource payload, bijvoorbeeld:

- `files` data
- pagination links/meta
- sort state mag buiten of binnen de key, zolang key exact is

De folder en ancestors kunnen apart gecachet worden, maar dat is fase 2. Begin klein met alleen `files`.

## TTL

Start met:

```txt
TTL: 60 seconden
```

Waarom kort:

- Minder risico op stale UI bij bugs in invalidatie.
- Toch al winst bij refresh, terugnavigeren en snelle repeated requests.

Later verhogen naar 5-10 minuten als invalidatie betrouwbaar is.

## Invalidatiepunten

Flush tags bij elke mutatie die een folder listing kan veranderen:

- create folder
- batch upload complete
- multipart upload complete
- abort hoeft listing niet te invalidaten, tenzij er al een file record was gemaakt
- delete/move to trash
- restore from trash
- permanent delete
- toekomstige rename/move/share changes als die lijstkolommen wijzigen

Praktisch:

```php
Cache::tags([
    "user:{$user->id}:files",
    "user:{$user->id}:folder:{$folderId}",
])->flush();
```

Bij mutaties met onbekende of meerdere folders:

```php
Cache::tags(["user:{$user->id}:files"])->flush();
```

Dat is grover, maar veilig.

## Service voorstel

Maak later een kleine service:

```txt
FileListCache
```

Verantwoordelijkheden:

- cache key bouwen
- tags bouwen
- `remember()` wrapper
- invalidatie per folder/user

Mogelijke methods:

```php
rememberListing(User $user, File $folder, array $params, Closure $callback)
flushFolder(User $user, File|int $folder)
flushUser(User $user)
```

Hou de controller dun; de controller mag alleen params normaliseren en de service aanroepen.

## Query stabiliteit

Voor stabiele paginatie moet de sort altijd deterministic zijn. Voeg bij gelijke waarden een vaste secondary sort toe:

```php
->orderBy('is_folder', 'desc')
->orderBy($sortColumn, $sortDirection)
->orderBy('id')
```

Dat voorkomt dat items tussen pagina's verspringen als veel records dezelfde `size` of `updated_at` hebben.

## Fases

### Fase 1: limit en deterministic sort

- Default limit verhogen naar 50.
- Max limit begrenzen op 100.
- Secondary sort op `id` toevoegen.

### Fase 2: cache service

- `FileListCache` service toevoegen.
- Alleen `myFiles` listing cachen.
- TTL 60 seconden.
- Cache key bevat user, folder, page, limit, sort.

### Fase 3: invalidatie

- Flush bij create folder.
- Flush bij batch upload en multipart complete.
- Flush bij delete/restore/permanent delete.
- Bij twijfel user-level flush gebruiken.

### Fase 4: uitbreiden

- Trash listing cachen.
- Ancestors/folder resource los cachen als dit zichtbaar nodig is.
- Metrics toevoegen voor cache hits/misses.

## Risico's

- Verkeerde invalidatie geeft stale folder views.
- Tags werken alleen met stores die tags ondersteunen.
- Paginated resources cachen kan verwarrend zijn als resource shape wijzigt; cache keys of TTL lossen dat meestal op.
- Infinite scroll moet query string `limit` behouden via `withQueryString()`.

## Acceptatiecriteria

- F5 in een volle folder toont direct genoeg rows om minder zichtbaar bij te laden.
- Sorteren blijft dezelfde query params gebruiken.
- Upload complete reloadt de file list en toont nieuwe file zonder handmatige refresh.
- Cache implementatie mag nooit presigned S3 URLs cachen.

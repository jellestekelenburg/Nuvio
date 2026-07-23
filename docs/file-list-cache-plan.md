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

---

# Aanvullend plan: bestanden en folders verplaatsen

## Doel en gekozen richting

Verplaatsen wordt één domeinactie met twee bewust gescheiden interfaces:

1. **Selecteren + `Verplaatsen naar…`** is de primaire flow. Deze werkt met muis,
   toetsenbord en touch en is het realistische hoofddoel voor de eerste oplevering.
2. **Drag-and-drop** wordt daarna toegevoegd als desktop-enhancement en start alleen
   na echte muisinvoer. Touch krijgt geen drag listeners of long-pressgedrag.

Beide interfaces gebruiken exact dezelfde backendservice, validatie, naamconflictlogica,
folderbrowser en client-side submitfunctie. Daardoor kan drag-and-drop later geen tweede,
afwijkende implementatie worden.

## Bevindingen uit de huidige code

- `MyFiles.vue` beheert selectie nu lokaal met `selected`, `allSelected` en
  `selectedIds`. De delete- en downloadknoppen ontvangen die selectie via props.
- De lijst bevat alleen directe kinderen van de geopende folder. De normale selectie
  bestaat daardoor uit siblings, maar de backend moet ook veilig blijven als later items
  uit meerdere locaties tegelijk geselecteerd kunnen worden.
- `File` gebruikt `kalnoy/nestedset` v6.0.8. Een bestaand node kan met
  `appendToNode($target)->save()` inclusief volledige subtree worden verplaatst. De
  library weigert een move in zichzelf of een descendant, maar deze fout moet vooraf
  als nette domeinvalidatie worden afgehandeld.
- Nested-setverplaatsing past `_lft`, `_rgt` en `parent_id` aan. Dat is voldoende voor
  de hiërarchie; een daarnaast opgeslagen volledig `path` dupliceert die informatie en
  veroorzaakt write amplification bij rename en move.
- Foldernavigatie gebruikt stabiele folder-id's. Een volledig zichtbaar pad wordt waar
  nodig afgeleid uit ancestors en namen, niet opgeslagen als identiteit.
- `AvailableNodeNameService` ondersteunt al veilige numerieke suffixen, extensies,
  dotfiles en gereserveerde namen binnen één batch. Deze service kan dus ook de
  automatische hernoeming tijdens verplaatsen verzorgen.
- `storage_path` is onafhankelijk van de zichtbare folderstructuur. Een move is alleen
  een metadata/tree-operatie; S3/object storage hoeft niet gekopieerd of hernoemd te
  worden.
- De voormalige `path`-route was niet uniek of geïndexeerd en verschillende namen konden
  dezelfde `Str::slug()` opleveren. ID-routes verwijderen die ambiguïteit; naamconflicten
  blijven per directe parent afgehandeld worden.
- `updated_by` wordt bij bestaande records niet automatisch gewijzigd, omdat de trait
  alleen invult wanneer het veld `null` is. De moveservice zet `updated_by` daarom
  expliciet op de handelende gebruiker.
- Het cacheplan noemt move al als toekomstig invalidatiepunt. Een move raakt minimaal
  alle bronfolders en de doelfolder; bij twijfel is een user-level flush veilig.
- Er is nog geen frontend-testomgeving. Backendregels kunnen direct met feature- en
  servicetests worden afgedekt; UI-interactie krijgt daarnaast build/type checks en een
  vaste handmatige testmatrix.

## Domeincontract

### Endpoint

Voeg een gerichte route toe, bijvoorbeeld:

```txt
PATCH /file/move
```

Request:

```json
{
    "ids": [12, 18, 21],
    "target_parent_id": 44
}
```

Gebruik altijd de echte root-id voor `My Files`; gebruik `null` niet als verborgen
alias voor root. Dat houdt autorisatie en clientcode expliciet.

Maak een aparte `MoveFilesRequest`; hergebruik `FilesActionsRequest` niet. De nieuwe
request valideert minimaal:

- `ids` is een niet-lege, begrensde en `distinct` lijst;
- alle items zijn actief, niet verwijderd en eigendom van de gebruiker;
- de root kan niet worden verplaatst;
- `target_parent_id` is een actieve, eigen folder;
- de bestemming is niet een geselecteerde folder of een descendant daarvan.

De controller doet alleen request/response-afhandeling en roept de domeinservice aan.

### `FileMoveService`

Voorgesteld contract:

```php
move(User $user, array $nodeIds, File $targetParent): FileMoveResult
```

Verantwoordelijkheden:

1. Start één database-transactie.
2. Haal target en nodes opnieuw op met locks en user-scope.
3. Valideer de volledige batch vóór de eerste mutatie.
4. Normaliseer defensief een selectie die zowel een ancestor als zijn descendant bevat:
   alleen de hoogste geselecteerde node wordt verplaatst.
5. Bewaar de nested-setvolgorde (`_lft`) zodat een batch voorspelbaar in de doelfolder
   aankomt.
6. Bepaal per node de definitieve beschikbare naam, met een gereserveerde namenlijst
   voor eerdere nodes uit dezelfde batch.
7. Verplaats elke top-level node via de nested-set API.
8. Werk de eventuele naam en `updated_by` bij; `storage_path` en descendantmetadata
   blijven ongemoeid.
9. Commit alles of rol de hele batch terug.
10. Retourneer een result object met aantallen, bronfolder-id's, doelfolder-id en
    eventuele `{id, old_name, new_name}`-hernoemingen.

Doe alle cyclusvalidatie vóór het verplaatsen. Bounds van nodes veranderen tijdens een
multi-move; validatie halverwege de mutatie zou daardoor onnodig kwetsbaar zijn.

### Naamconflicten en automatische hernoeming

De aanbevolen standaard is automatisch hernoemen:

```txt
rapport.pdf   -> rapport-1.pdf
Project       -> Project-1
.env          -> .env-1
```

Daarvoor wordt `AvailableNodeNameService` hergebruikt. Benodigde uitbreiding:

- reeds gekozen namen uit dezelfde movebatch reserveren;
- een node die al in de doelfolder staat niet tegen zichzelf laten conflicteren;
- de daadwerkelijke hernoemingen teruggeven aan de UI.

Geen replace/overwrite toevoegen in deze reeks. Dat heeft andere opslag-, trash- en
herstelsemantiek en hoort later als expliciete conflictstrategie te worden ontworpen.

### ID-gebaseerde foldernavigatie

Gebruik de onveranderlijke folder-id als routeparameter en `parent_id` als directe
containment-relatie. Breadcrumbs komen uit de bestaande ancestors-query. Een rename
wijzigt alleen de naam; een move wijzigt de tree-relatie via de nested-set API. Daardoor
blijven URLs en referenties stabiel en hoeft geen volledige subtree voor een afgeleid
pad te worden herschreven. `storage_path` blijft altijd onafhankelijk hiervan.

## Folderbrowser voor de knopflow

Maak een kleine read-only endpoint voor directe subfolders, bijvoorbeeld:

```txt
GET /api/folders?parent_id=44
```

Response bevat alleen wat de picker nodig heeft:

- huidige folder;
- ancestors/breadcrumbs;
- directe actieve subfolders van de gebruiker;
- eventueel `has_children` om een navigatie-indicator te tonen;
- paginatie of een harde, gedocumenteerde limiet voor zeer volle folders.

Laad folders lazy per niveau; stuur niet de volledige boom naar de browser. De picker
kan een bestemming op basis van geselecteerde folder-id's en door de server teruggegeven
targetvaliditeit alvast disabled tonen, maar de move-endpoint blijft altijd leidend.

## Gedeelde frontendbouwstenen

### Selectie

Trek de selectie uit `MyFiles.vue` naar een kleine `useFileSelection` composable zodra
de knopflow wordt gebouwd. Die beheert:

- geselecteerde ids en items;
- select-all voor de momenteel geladen lijst;
- shift-select;
- `clear()` na een succesvolle actie;
- `idsForAction(file)` voor toekomstig slepen: een geselecteerde row neemt de selectie
  mee, een niet-geselecteerde row alleen zichzelf.

Leg select-all expliciet vast als **alle geladen items**, niet impliciet alle records in
de folder. De huidige `all=true`-semantiek van andere acties is anders en moet niet naar
move lekken.

### Submitlogica

Maak `useMoveFiles` als enige client-side ingang voor de move-endpoint:

- processing-state en dubbel submitten voorkomen;
- ids + target verzenden;
- Inertia partial reload van `files`, `folder` en `ancestors`;
- selectie na succes leegmaken;
- toast met `x items verplaatst` en, indien nodig, `y automatisch hernoemd`;
- validatie- of netwerkfouten consistent tonen.

Zowel de dialog als drag-and-drop roepen alleen deze composable aan.

### Componenten

Voorgestelde scheiding:

- `MoveFilesButton.vue`: selectie-actie en openen van de dialog;
- `MoveFilesDialog.vue`: responsive modal/bottom-sheet, bevestiging en bestemming;
- `FolderPicker.vue`: lazy foldernavigatie, breadcrumbs en loading/error states;
- later `useMouseFileDrag.ts`: uitsluitend muis-dragstate en dropcoördinatie;
- kleine `FileDropTarget`-directive of composable voor row, breadcrumb en sidebar,
  zodat hover/dropgedrag niet drie keer wordt gekopieerd.

De knop blijft ook op desktop beschikbaar. Daarmee bestaat altijd een toetsenbord- en
screenreader-vriendelijke fallback als slepen niet kan of niet gewenst is.

## Strikte scheiding tussen muis en touch

Drag-and-drop wordt niet alleen met een CSS-breakpoint afgeschermd. Op hybride laptops
kan immers zowel touch als een muis aanwezig zijn.

Voorwaarden om een drag te starten:

- de browser meldt een fine pointer/hover-capability;
- de voorafgaande `pointerdown` heeft `event.pointerType === 'mouse'`;
- er is een geldige `DragEvent` met `dataTransfer`;
- touch- en peninput starten nooit de dragservice.

De touch/mobile flow registreert geen drag-events en gebruikt uitsluitend selectie + de
`Verplaatsen naar…`-dialog. Een drag-end-event onderdrukt de eventuele afsluitende
row-click, zodat de selectie niet per ongeluk omslaat.

## Drag-and-dropgedrag na de knopflow

### Bronnen

- Drag op een geselecteerde row: verplaats de volledige selectie.
- Drag op een niet-geselecteerde row: verplaats alleen die row en maak dat visueel
  duidelijk.
- De drag preview toont naam bij één item en `N items` bij meerdere items.

### Doelen

In deze volgorde toevoegen:

1. folders die zichtbaar in de huidige filelijst staan;
2. ancestorfolders in de breadcrumbs;
3. `My Files` in de desktop-sidebar als root-dropzone.

Alle targets gebruiken dezelfde target-state en dezelfde submitfunctie. Een target toont
alleen hoverfeedback bij een actieve geldige muisdrag. `drop` stopt navigatie/click en
een mislukte backendvalidatie herstelt de bestaande selectie.

Niet in de eerste dragversie:

- hover-to-open van folders;
- automatisch scrollen;
- touchdrag of long-press;
- droppen op Trash/Shared;
- overwrite/replace;
- undo.

## Samenhang met de file-list cache

Na een succesvolle move zijn minimaal deze listings stale:

- iedere unieke bronfolder;
- de doelfolder.

Wanneer `FileListCache` al bestaat, invalidatie pas na een succesvolle transactie doen:

```txt
flushFolder(user, sourceFolderA)
flushFolder(user, sourceFolderB)
flushFolder(user, targetFolder)
```

Bij een verplaatste folder veranderen de ancestorgegevens, maar niet de stabiele id of
URL. Zolang folder/ancestor-responses nog niet apart gecachet worden, volstaat
listing-invalidatie. Zodra die wel gecachet worden, moet de moveservice of een mutation
invalidator ook ancestorcaches van de verplaatste subtree invalidaten. Totdat precieze
invalidatie bewezen is, is `flushUser(user)` na move de veilige keuze.

De folderbrowser krijgt in de eerste versie geen eigen langlevende cache. Als die later
wel wordt gecachet, gebruikt hij dezelfde user/folder-tags en mutatie-invalidatie als de
normale listing.

## Gefaseerde uitvoering

### M0 — Invarianten en regressietests

- Test helpers voor user-root, folders en files consolideren.
- Bestaande nested-settree en parentgedrag vastleggen.
- ID-gebaseerde navigatie en user-scoping vastleggen.
- De afgeleide `path`-kolom verwijderen nadat alle reads zijn omgezet.
- Vastleggen dat move geen `storage_path` wijzigt.

**Klaar wanneer:** de huidige invarianten expliciet getest zijn en er geen onbesliste
naam/parentregel meer in de implementatie zit.

### M1 — Backendfundament

- `FileMoveResult`, `FileMoveService` en `MoveFilesRequest` maken.
- Move-route/controller toevoegen.
- Automatische conflict-hernoeming via `AvailableNodeNameService` toevoegen.
- Transactie, locks, autorisatie, cyclusbescherming en top-level normalisatie testen.

**Klaar wanneer:** service/featuretests files, folders, subtrees en gemengde batches
veilig kunnen verplaatsen zonder UI.

### M2 — Lazy folderbrowser

- Eigen read-only folder-endpoint toevoegen.
- `FolderPicker.vue` met root, breadcrumbs, subfolders en loading/error/empty state.
- Ongeldige doelen client-side herkenbaar maken; backendcontrole blijft verplicht.

**Klaar wanneer:** iedere toegestane bestemming zonder paginanavigatie gekozen kan
worden, ook in een diepe folderstructuur.

### M3 — Selectie + knop `Verplaatsen naar…` (doel voor vandaag)

- Selectielogica naar `useFileSelection` trekken zonder bestaand gedrag te breken.
- `useMoveFiles`, button en dialog aansluiten.
- Responsive actiegebied maken zodat de knop op mobiel niet uit beeld of buiten de
  header valt.
- Succesmelding inclusief automatisch hernoemde items en nette foutafhandeling.
- Selectie alleen na succes opruimen.

**Klaar wanneer:** één file, meerdere files, één folder en folder + sibling-files via
de knop naar root of een andere folder kunnen worden verplaatst op desktop en mobiel.

### M4 — Muisdrag naar zichtbare folders

- `useMouseFileDrag` toevoegen met strikte pointertype-check.
- Row als drag source en folder-row als drop target aansluiten.
- Drag preview, geldige/ongeldige hoverstate en click-suppressie toevoegen.
- Dezelfde `useMoveFiles` submitfunctie gebruiken als M3.

**Klaar wanneer:** muisdrag dezelfde resultaten en meldingen geeft als de knop, terwijl
touch en pen uitsluitend de knopflow houden.

### M5 — Breadcrumbs en `My Files`

- Breadcrumb-items als drop target aansluiten.
- Desktop-sidebar `My Files` als root-target aansluiten.
- Componentoverschrijdende dragstate klein en expliciet houden; geen filedata in losse
  globale DOM-events dupliceren.
- Navigatie blijft normaal werken buiten een actieve drag.

**Klaar wanneer:** een selectie met de muis naar een ancestor of root kan, zonder dat
de link onbedoeld navigeert tijdens de drop.

### M6 — Cachekoppeling en hardening

- Bron- en doellistings na commit invalidaten.
- Gelijktijdige moves/uploads naar dezelfde target testen.
- Grote selecties, diepe subtrees en rollback bij één ongeldig item testen.
- Eventueel metrics/logging voor move count, rename count en validatiefouten toevoegen.

## Backend-testmatrix

Minimaal afdekken:

- één file naar een andere folder en naar root;
- meerdere files in één batch;
- folder + sibling-file in één batch;
- folder met meerdere descendantniveaus, inclusief ongewijzigde parentrelaties binnen
  de verplaatste subtree;
- exacte naamconflicten voor file, folder, extensie en dotfile;
- twee items uit dezelfde batch die na move dezelfde naam zouden krijgen;
- move naar dezelfde parent als no-op;
- root verplaatsen weigeren;
- move naar file, trash-item, foreign-user-folder, zichzelf of descendant weigeren;
- ancestor + descendant tegelijk defensief normaliseren;
- volledige rollback bij één ongeldige node;
- nested-settree blijft geldig na een multi-move;
- `updated_by` wordt aangepast en `storage_path` blijft gelijk;
- juiste cache-invalidatie zodra de cache actief is.

## Handmatige UI-testmatrix

- desktopmuis, toetsenbord en smalle mobiele viewport;
- echt touchgedrag of device-emulatie: nooit een dragstart;
- hybride scenario: touch doet niets, muis kan wel slepen;
- enkele en meervoudige selectie, shift-select en select-all geladen items;
- dialog naar root, ancestor, sibling en diepe descendantfolder;
- auto-rename zichtbaar in toast;
- mislukte request behoudt selectie en toont een bruikbare fout;
- drag eindigt buiten een target zonder mutatie;
- breadcrumbs en sidebar navigeren normaal als er niet gesleept wordt;
- sortering en infinite scroll blijven na partial reload stabiel.

## Scopeadvies voor vandaag

Streef naar **M0 t/m M3**. Dat levert de volledige, veilige mobiele flow en tevens de
toegankelijke desktopflow. Begin pas aan M4 als backendtests, folderpicker en knopflow
af zijn. M5 is een logische volgende losse oplevering; die hoeft de bruikbare eerste
versie niet te blokkeren.

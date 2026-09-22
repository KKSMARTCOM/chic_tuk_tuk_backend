# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

ChicTukTuk — plateforme de réservation de courses (tuk-tuk) avec gestion de chauffeurs, véhicules,
contrats et propriétaires.

Ce dépôt est le **backend** (`api.chictuktuk.com`) : l'API v1 et l'interface Blade
historique encore en service. Les fronts Nuxt vivent dans leurs propres dépôts —
`landing` (vitrine + réservation publique, `chictuktuk.com`), puis `client` (espaces
authentifiés, `app.chictuktuk.com`). Le seul lien entre eux est l'API : un changement
de contrat se livre **ici d'abord**, et de façon rétrocompatible, puisque l'ancien front
continue d'appeler la nouvelle API le temps de son propre déploiement.

## Commandes

```bash
# Setup
composer install && npm install
cp .env.example .env && php artisan key:generate

# Développement (serveur + queue + logs + vite en parallèle)
composer run dev
# ou séparément :
php artisan serve
npm run dev            # Vite (JS)
npm run watch:css      # Tailwind CSS en watch (build classique, en plus du CDN)

# Build frontend
npm run build           # Vite
npm run build:css       # Tailwind CSS minifié
npm run build:all       # les deux

# Tests (PHPUnit — pas de Pest)
php artisan test
php artisan test --filter=NomDuTest
vendor/bin/phpunit tests/Feature/CheminDuTest.php

# Lint / format PHP (Laravel Pint, config par défaut — pas de pint.json)
vendor/bin/pint
vendor/bin/pint --test   # dry-run

# Migrations
php artisan migrate
php artisan migrate:fresh --seed

# Commandes métier (cf. section "Commandes artisan" plus bas)
php artisan app:expire-bookings
php artisan app:process-recurring-bookings
php artisan app:generate-daily
php artisan app:activate-leave-pauses
```

Les tests tournent sur une base PostgreSQL **dédiée** (`chic_tuktuk_db_test`, réglée dans
`phpunit.xml`) : SQLite n'est pas utilisable, les migrations et requêtes du projet étant
spécifiques à PostgreSQL. Création : `createdb -U postgres chic_tuktuk_db_test`.

⚠️ **Le dépôt n'a jamais été formaté par Laravel Pint** : `vendor/bin/pint --test` échoue
sur plus de 130 fichiers existants. Toujours lui passer explicitement les fichiers
nouvellement créés — un `vendor/bin/pint` sans argument, ou appliqué à un fichier
préexistant, produit un diff massif sans rapport avec la livraison en cours.

## Vocabulaire : « pause », jamais « congé »

Une absence d'agent s'appelle une **pause**, dans tout ce qui se lit — libellés,
titres, menus, messages d'erreur, notifications, commentaires. Jamais « congé » ni
« congés », y compris dans les tournures où le mot semblerait naturel : on écrit « solde
de pauses », « gestion des pauses ».

Le **code**, lui, reste sur son vocabulaire anglais `leave` — `LeaveRequest`,
`leave_days_used`, `view-leaves`, `/admin/leaves`, `LEAVE_NOT_DELETABLE`. Ces noms
traversent la base, les permissions Spatie, les routes et les codes d'erreur : les
renommer casserait plus que cela ne clarifierait. La consigne porte sur le mot FRANÇAIS
montré aux humains.

Deux choses portent le mot « pause » et ne sont pas la même : la **pause agent**
(`LeaveRequest`, une absence) et la **pause véhicule** (`VehiclePause`, un tricycle
immobilisé). Quand les deux apparaissent ensemble, les nommer en entier.

## Nommage : identifiants en anglais, commentaires en français

Les **identifiants** — variables, fonctions, méthodes, classes, propriétés, paramètres,
constantes — s'écrivent en **anglais**. Les **commentaires** et les docblocks restent en
**français**, comme les libellés affichés aux utilisateurs.

C'est la convention du projet, posée le 2026-09-22. Une partie du code antérieur porte
encore des noms français ; ne pas s'en servir comme précédent. Les renommer quand on
touche un fichier pour une autre raison est bienvenu ; une campagne transverse se décide
avec le mainteneur.

## Stack technique

- Laravel 11 (bootstrap/app.php, pas de Kernel.php)
- PostgreSQL
- Tailwind CSS (via CDN) + Font Awesome 6
- Laravel Sanctum (session web, guard unique `web`)
- Spatie Permission (rôles + permissions, guard `web`)
- Firebase FCM (notifications push)
- PWA (service worker, manifest)
- DataTables + Alpine.js (sidebar accordion)

## Architecture auth

- Un seul modèle `User` avec colonne `profil` : `admin`, `client`, `driver`, `owner`
- Sanctum via cookie httpOnly `ctt_{profil}_token`
- Session web + Sanctum combinés (`Auth::login()` + `createToken()`)
- Middleware `InjectSanctumTokenFromCookie` injecte le token Bearer avant auth
- 3 middlewares distincts (aliasés dans bootstrap/app.php) :
  - `profil:xxx` → `CheckProfil`, vérifie `$user->profil` (utilisé sur tous les groupes de routes : `admin`, `driver`, `client`, `owner`)
  - `permission:xxx` → `CheckPermission`, vérifie `$user->hasAnyPermission()` (Spatie, utilisé route par route pour les actions CRUD)
  - `role:xxx` → `CheckRole`, vérifie `$user->hasAnyRole()` (alias déclaré mais non utilisé actuellement dans les routes)
- Verrou compte : 5 tentatives → locked_until en base (5 min)

## Modèles principaux

### User

- profil : admin | client | driver | owner
- Rôles Spatie : admin (62 permissions), lecteur (41), driver (8), client (6),
  proprietaire (5) — 66 permissions au total.
  ⚠️ `driver` est passé de 7 à 8 le 2026-09-18 : `view-dashboard` lui manquait, alors que
  l'espace agent a un tableau de bord. La navigation du front se construisant sur les
  permissions EFFECTIVES, l'agent n'avait aucune entrée de menu vers son écran d'accueil.
  ⚠️ Le rôle `client` avait le même défaut, **corrigé le 2026-09-21** : il est passé de 6
  à 7 permissions avec `view-dashboard`. `tests/Feature/Identity/RolePermissionCoverageTest.php`
  parcourt désormais les routes déclarées et vérifie que le rôle de référence de chaque
  profil porte les permissions que son espace exige — cette classe de défaut s'était
  produite deux fois.
- ⚠️ **`lecteur` n'est PAS un rôle en lecture seule**, contrairement à ce que ce fichier
  affirmait et à ce que suggère son libellé de production, « Utilisateur ». Sur ses 41
  permissions, **27 sont des écritures** : `create-drivers`, `edit-drivers`,
  `manage-payments`, `manage-settings`, `manage-commissions`, `manage-pricing`,
  `approve-leave-requests`, `delete-leaves`, `moderate-testimonials`… Il ne lui manque,
  par rapport à `admin`, que la gestion des rôles, des permissions et des véhicules.
  Vérifié en base le 2026-09-17 ; l'état est conservé sur décision explicite, mais le
  libellé décrit mal ce niveau d'accès.
- **La source de vérité des rôles et permissions est
  `database/seeders/ReferenceRolesAndPermissionsSeeder.php`**, et le code y fait foi :
  `syncPermissions` remet chaque rôle dans l'état décrit, donc toute attribution faite
  depuis l'écran d'administration des rôles est **temporaire**. Une permission qui doit
  durer s'ajoute dans ce fichier. C'est ce qui empêche la base de développement et la
  production de divergenter — deux écarts avaient été relevés le même jour avant sa
  mise en place.
- Relations : driver(), vehicles(), vehicleContracts(), fcmTokens(), pushSubscriptions()

### Booking

- Colonnes clés : is_recurring, parent_booking_id, subscription_driver_id,
  trip_type (go/return), round_trip, return_time, week_days,
  remaining_days, next_recurring_date, subscription_end_date,
  is_revoked, revoked_at, expired_at, status (pending/confirmed/in_progress/completed/cancelled/expired)
- Accessors : is_subscription_parent, is_subscription_child, is_simple_return,
  subscription_label, subscription_index, commission_preview, driver_earning_preview
- Méthode : isVisibleToDriver(driverId)

### Driver

- Relations : driverContracts(), activeDriverContract(), currentVehicle()
- Colonnes : failed_login_attempts, locked_until, last_failed_login (sur User)

### Vehicle

- Relations : owner(), vehicleContracts(), activeVehicleContract(),
  driverContracts(), activeDriverContract(), pauses(), activePause()
- Méthode : isOnPause(), total_pause_days (accessor)

### VehicleContract

- Colonnes : total_amount, monthly_payment, contract_months,
  unlimited_internet, spotify_premium, manager_remuneration,
  status (active/completed/cancelled)
- Accessors : total_paid, remaining_amount, surplus, progress_percentage
- Constantes : App\Consts\VehicleContractConsts::TOTAL_AMOUNTS, MONTHLY_PAYMENTS,
  DEFAULT_UNLIMITED_INTERNET, DEFAULT_SPOTIFY_PREMIUM, DEFAULT_MANAGER_REMUNERATION

### DriverContract

- Relations : driver(), vehicle(), vehicleContract(), leaveRequests(), payments(), vehiclePauses()
- Accessors : months_elapsed, accrued_leave_days, used_leave_days, available_leave_days, total_paid
- Méthode : isActive()

### VehiclePause

- reason_type : agent_leave | agent_change | technical | accident | legal | other
- is_auto : true si créée automatiquement par pause agent

### Payment

- Colonnes : driver_id, vehicle_contract_id, driver_contract_id,
  payment_month, payment_type (commission/contract/bonus/other), net_amount, amount,
  status (pending/completed/cancelled/failed)
- Paiements journaliers auto via commande `app:generate-daily` (lun-ven uniquement)

### LeaveRequest

- Colonnes : driver_id, driver_contract_id, dates (array), rejection_reason,
  status (pending/ongoing/completed/rejected — « approved » a été retiré par la migration
  2026_08_12_220013 : une demande acceptée passe directement à `ongoing`)

## Logique booking — points critiques

### Courses simples

- Course unique sans AR : status=pending, visible par tous
- Course unique avec AR : course aller créée + course retour cachée (subscription_driver_id=null)
- Quand agent accepte l'aller → subscription_driver_id=A sur la retour → visible par A seulement
- Course retour : is_simple_return = parent_booking_id non null + parentBooking.is_recurring=false

### Abonnements

- Parent : is_recurring=true, whereNull(parent_booking_id)
- Enfants : is_recurring=false, whereNotNull(parent_booking_id), parentBooking.is_recurring=true
- Cron à 1h du matin (lun-dim selon week_days) : crée J+1 depuis le parent
- next_recurring_date = veille du jour J+1 à 1h
- subscription_driver_id propagé depuis le parent sur tous les enfants
- Révocation : is_revoked=true, subscription_driver_id=null sur la course révoquée uniquement
- Résiliation : il n'existe PAS de statut `suspended` (ni en base, ni dans le code).
  Un abonnement résilié est annulé : `cancel()` sur le parent passe le parent et ses
  enfants pending en `cancelled`, la suppression se fait ensuite si besoin.

### take() — Acceptation

- Vérifie isVisibleToDriver()
- Abonnement parent sans titulaire → subscription_driver_id=A + retour abonnement liée
- Course aller simple AR → subscription_driver_id=A sur la retour simple

### cancel() — 3 cas

1. Enfant abonnement → cancelled + copie liée au titulaire (agent doit révoquer depuis disponibles)
2. Parent abonnement → cancelled + enfants pending annulés + recréation parent (+ retour cachée si AR)
3. Course unique → cancelled + recréation (+ gestion course retour si AR)

### getAvailableBookings(driverId)

- Course unique aller (avec ou sans AR) → tout le monde
- Abonnement parent sans titulaire → tout le monde
- Abonnement parent avec titulaire → titulaire seul
- Enfant abonnement lié → titulaire seul
- Enfant abonnement révoqué → tout le monde
- Retour simple liée → agent seul (qui a accepté l'aller)
- Retour d'abonnement liée → agent seul (branche omise de ce fichier jusqu'au 2026-09-18 ;
  la requête en compte **neuf**, pas huit)
- Retour révoquée → tout le monde
- ⚠️ La transposition en `App\Domains\Booking\Application\Actions\ListAvailableBookings`
  est prouvée équivalente par `AvailableBookingsDifferentialTest` — dix formes, trois
  observateurs, listes ordonnées. C'est ce test, et non cette liste, qui fait foi.

### getByDriverId(driverId, status = null, search = null)

- driver_id = driverId, et rien d'autre. **Aucune recomposition** avec
  `subscription_driver_id` : la description antérieure évoquait un « OU retour simple
  pending liée », que le code ne fait pas et que les deux écrans appelants ne compensent
  pas. Corrigé le 2026-09-18 après vérification dans le code.
- Le paramètre `$search` n'est appelé par personne : l'écran d'historique a sa propre
  requête dans `PageController::historiesBookings`.
- ⚠️ Cette méthode ne sert PLUS l'API v1. L'espace agent lit par
  `App\Domains\Booking\Application\Actions\ListAssignedBookings` (courses acceptées) et
  `ListBookingHistory` (historique, paginé par 10). Elle reste pour le chemin Blade.

## Services principaux

Tous dans `app/Services`, injectés dans les contrôleurs (pas de logique métier dans les contrôleurs).

- BookingService : create, createFromAdmin, update (\_partial pour status/driver), take,
  cancel, complete, start, revokeFromSubscription, getAvailableBookings,
  getByDriverId, createRecurringBookings, markExpiredBookings,
  calculateEndDate, getNextAllowedDay
- ⚠️ `take`, `cancel`, `complete`, `start` et `revokeFromSubscription` **DÉLÈGUENT**
  depuis le 2026-09-18 à `App\Domains\Booking\Application\Actions\*`. Elles ne
  contiennent plus de logique : toute modification de comportement se fait dans l'action,
  jamais ici, sous peine de recréer deux implémentations divergentes de la cascade de
  recréation. Les actions lèvent des `ApiException` portant statut et code ; comme celle-ci
  hérite d'`Exception`, les `catch (\Exception)` du chemin Blade continuent de fonctionner
  et affichent les mêmes messages flash.
- PricingService : getDistance (OpenRouteService, clé dans config('services.openrouteservice.key')), getPrice
- PaymentService : create, generateDailyContractPayments, generateDailyPaymentForContract
- VehicleService : create, update, toggleStatus, pauseVehicle, endPause, createAutoAgentPause
- VehicleContractService : create, getStats
- DriverContractService : create, end, getStats
- UserService : create, update, updatePassword, toggleStatus, delete, generatePassword
- AuthService : login, logout, checkRateLimit, isAccountLocked, getLockRemainingTime,
  incrementLoginAttempts, resetLoginAttempts
- CommissionService, DriverService, OwnerService, TestimonialService, ZoneService : CRUD/stats dédiés à leur modèle
- FcmNotificationService : envoi de notifications push (kreait/laravel-firebase)

## Contrôleurs & routes

- `app/Http/Controllers/Admin/*` → espace admin (`routes/admin.php`, préfixe `admin.`)
- `app/Http/Controllers/Client/*` → espace client ET espace propriétaire (`routes/client.php`, préfixe `client.`
  et préfixe `owner.` **dans le même fichier** — il n'y a pas de `routes/owner.php` séparé)
- `app/Http/Controllers/Web/*` → auth, dashboard, bookings driver, settings, FCM (partagé entre profils)
- `app/Http/Controllers/Api/PricingController` → endpoint public de calcul de prix
- `routes/web.php` charge admin.php, client.php et driver.php via `require`

Gating par groupe de routes (voir "Architecture auth") :

- `/admin/*` → `auth:sanctum` + `profil:admin`, puis `permission:xxx` par action
- `/driver/*` → `auth:sanctum` + `profil:driver`
- `/client/*` → `auth:sanctum` + `profil:client`
- `/owner/*` (défini dans routes/client.php) → `auth:sanctum` + `profil:owner`
- /fcm/token → POST (enregistrement token FCM)
- /install → page installation PWA
- /admin/owners/{owner}/vehicles → AJAX véhicules d'un propriétaire

## API v1 (migration vers front Nuxt)

Le projet migre vers trois applications séparées : `landing` (Nuxt, chictuktuk.com),
`client` (Nuxt, app.chictuktuk.com) et ce backend réduit à une API (api.chictuktuk.com).
Authentification par **token Bearer** Sanctum, pas de cookie stateful.

Conventions du nouveau code — ne pas réintroduire les anciennes :

- **Pas de FormRequest ni de JsonResource.** Une classe `Data` (spatie/laravel-data)
  porte à la fois les règles de validation en entrée et la sérialisation en sortie.
  Base commune : `App\Shared\Data\BaseData` (mapping snake_case dans les deux sens).
- **Découpage DDD** sous `app/Domains/{Contexte}/` : `Domain/` (modèles, Enums, règles),
  `Application/` (Data, Actions, Queries), `Presentation/Api/V1/`. Contextes retenus :
  Booking, Identity, Fleet, Workforce, Finance, Notification, Content.
- **Enums plutôt que `app/Consts`** pour les valeurs contraintes. Leurs valeurs reflètent
  les contraintes CHECK PostgreSQL — les modifier impose une migration de données.
- **Morph map obligatoire** (`AppServiceProvider::MORPH_MAP`) : la base stocke des alias
  (`user`, `booking`…) et non des FQCN, pour que les classes puissent être déplacées sans
  invalider rôles, permissions et tokens Sanctum.
- **Erreurs JSON** normalisées par `App\Shared\Http\ApiExceptionRenderer`
  (`{message, code, errors?}`). Le chemin web/Blade conserve son rendu historique.
- **Erreurs métier : on lève.** `ValidationException` donne un 422 avec ses `errors` ;
  `App\Shared\Http\ApiException` porte un statut, un code et des champs choisis
  (par ex. `ACCOUNT_LOCKED` avec `retry_after`, `PROFIL_AMBIGUOUS` avec `profils`).
- **Échecs imprévus : chaque méthode de contrôleur les attrape**, dans un `try/catch`
  explicite, comme les contrôleurs Blade du projet. Le rendu branché dans
  `bootstrap/app.php` couvre déjà toute exception, mais il ne sert que de dernier
  recours : le contrôleur journalise avec son contexte métier et renvoie un **code
  propre à l'opération** (`LOGIN_FAILED`, `LOGOUT_FAILED`, `PROFILE_READ_FAILED`,
  `PASSWORD_CHANGE_FAILED`, `PASSWORD_RESET_FAILED`) plutôt qu'un `SERVER_ERROR`
  générique, ce qui donne au front de quoi réagir et au support de quoi filtrer les
  journaux.

  Trois règles dans chacun de ces `try/catch` :

  1. ⚠️ **Relancer d'abord `ValidationException` et `ApiException` intactes**, dans un
     `catch` placé avant le général — sans quoi un refus d'identifiants (422) ou un
     verrou de compte (423) se transformerait en 500.
  2. Attraper **`\Throwable`**, pas `\Exception` : les `\Error` (TypeError,
     ValueError) n'héritent pas d'`Exception` et passeraient sous le nez du `catch`.
  3. Le message de l'exception va **au journal seulement**. Contrairement au chemin
     Blade où il finit en message flash, ici il partirait au client, et il contient
     régulièrement un fragment SQL ou un chemin de fichier.

  `tests/Feature/Identity/InternalFailureTest.php` verrouille les trois.

  Exception unique : `PasswordController::forgot` journalise **sans renvoyer d'erreur**.
  Un échec ne peut y survenir que pour une adresse existante, donc toute réponse
  distinguable rouvrirait l'énumération des comptes.
- Les traces ne fuitent jamais quand `APP_DEBUG` est à `false`
  (`tests/Feature/Identity/ErrorHandlingTest.php`). Le chemin Blade, lui, conserve son
  rendu historique.
- **Endpoints publics** (`routes/api/v1/public.php`) : throttlés, sans champ de prix en
  entrée — le tarif est toujours recalculé côté serveur.

Routes existantes : `GET /api/v1/health`, `GET /api/v1/public/pricing/quote`,
`POST /api/v1/public/bookings`, et l'authentification : `POST /api/v1/auth/login`,
`POST /api/v1/auth/logout`, `GET /api/v1/auth/me`, `POST /api/v1/auth/password`,
`POST /api/v1/auth/password/forgot`, `POST /api/v1/auth/password/reset`.

**Espace propriétaire** (`routes/api/v1/owner.php`) : quatre lectures sous
`['token.fresh', 'auth:sanctum', 'abilities:owner']` + `permission:view-own-*`.

**Espace administration** (`routes/api/v1/admin.php`), sous
`['token.fresh', 'auth:sanctum', 'abilities:admin']` + une permission par route :
`GET /admin/dashboard` (`view-dashboard`), `GET /admin/leaves` et
`GET /admin/leaves/{driver}` (`view-leaves`), `GET /admin/leave-requests`
(`view-leave-requests`).

⚠️ Les routes de pauses prennent l'identifiant de l'**AGENT** (`drivers.id`), là où le
Blade emploie celui de son **COMPTE** (`users.id`). Les deux sont des uuid et se
confondent sans rien casser de visible : les réponses portent `id` et `user_id`
explicitement.

⚠️ **`edit-leaves` a été ajoutée au catalogue le 2026-09-22.** Le quatuor
view/create/edit/delete existe pour dix domaines ; les pauses n'en avaient que trois, si
bien que clôturer ou corriger une pause n'avait aucune permission honnête à porter — les
routes Blade correspondantes n'en exigent d'ailleurs aucune. Accordée à `admin` (par
calcul) et à `utilisateur`. **Rejouer le seeder après déploiement.**

⚠️ **La liste des pauses montre AUSSI les anciens agents**, ceux qui ne sont pas allés au
bout de leur contrat — demandé le 2026-09-22. Le contrôleur Blade ne listait que les
agents sous contrat actif, et leur dossier devenait inconsultable dès leur départ. Le
point délicat n'est pas le filtre mais le CALCUL : leur solde est rapporté à leur DERNIER
contrat (`Driver::contratDeReference()`), faute de quoi la ligne n'afficherait que des
zéros. `App\Domains\Workforce\Domain\LeaveBalance` porte la formule, paramétrée par le
contrat, et sert les deux lectures — celle de l'agent (contrat actif, zéro sans contrat)
et celle de l'administration (contrat de référence). Une seule implémentation : deux
divergeraient, et c'est ce qui avait produit l'écran contradictoire du 2026-09-21.

⚠️ L'acquisition d'un ancien agent s'arrête à la date de FIN de son contrat. Sans cette
borne, quelqu'un parti il y a deux ans continuerait d'accumuler deux jours par mois.

**Les huit écritures de pauses** (approuver, refuser, clôturer, poser une pause en cours
ou historique, corriger l'une ou l'autre, supprimer) vivent dans
`app/Domains/Workforce/Application/Actions/`. ⚠️ `Admin\LeaveController` **DÉLÈGUE**
depuis le 2026-09-22, comme `BookingService` pour les courses : toute modification de
comportement se fait dans l'action, jamais dans le contrôleur, sous peine de recréer deux
implémentations divergentes. Les refus sont des `ApiException` portant les messages
d'origine mot pour mot ; le contrôleur les rend en flash, à l'identique.

⚠️ **Ce sont les EFFETS DE BORD qui se perdent en transposant**, parce qu'ils ne se voient
pas dans la réponse. Approuver crée une pause VÉHICULE et rend l'agent indisponible — mais
seulement si la pause commence aujourd'hui ou avant. Clôturer recompte les jours OUVRÉS
réellement pris, clôture la pause véhicule, et ne rend l'agent disponible que s'il ne lui
reste AUCUNE autre pause en cours. Corriger une pause en cours répercute la date sur la
pause véhicule — les laisser diverger est le défaut le plus coûteux de cet écran.

⚠️ **Une pause TERMINÉE ne se corrige et ne se supprime que si elle est d'origine
ADMINISTRATIVE** (`admin_historical`, `legacy`). Une pause terminée issue d'une demande
d'agent a été vécue : la réécrire ou l'effacer changerait un fait, pas une saisie.

⚠️ **Une pause EN COURS se supprime, quelle que soit son origine** — ajouté le
2026-09-22. Rien n'a encore été consommé. Avant cela, une pause posée par erreur n'avait
aucune issue : on ne pouvait que la « clôturer », ce qui enregistre des jours effectifs et
les consomme, gardant la trace d'une absence qui n'a pas eu lieu.

La suppression DÉFAIT les effets : la pause véhicule est **annulée** (`cancelPause`) et
non clôturée — la clôturer laisserait dans l'historique du propriétaire une
immobilisation fictive —, et l'agent redevient disponible. ⚠️ Pour une pause née d'une
demande d'agent, l'effacement emporte la demande : l'agent devra la redéposer.

⚠️ **La disponibilité se décide sur la DATE, pas sur le statut.** Une pause `ongoing`
dont le début est à venir ne bloque personne — `AddOngoingLeave` et `ApproveLeaveRequest`
laissent d'ailleurs l'agent disponible dans ce cas. `UpdateOngoingLeave` et `DeleteLeave`
suivent la même règle depuis le 2026-09-22 : `hasOngoingLeave()` ne suffit pas, il répond
oui pour une pause future. Et on ne libère jamais un agent qu'une AUTRE pause,
réellement commencée, retient.

⚠️ Le profil `admin` recouvre DEUX rôles très différents : `admin` (62 permissions) et
`utilisateur` — anciennement `lecteur`, libellé « Utilisateur » — qui en porte 41 dont 27
écritures. La garde route par route est ce qui les distingue : ne jamais s'appuyer sur
`profil:admin` seul pour protéger une écriture.

⚠️ La barre latérale Blade de l'admin n'est gardée que par `profil === 'admin'`, sans
aucun contrôle de permission : elle propose donc à un `utilisateur` des liens qu'il ne
peut pas ouvrir. La navigation du front Nuxt se construisant sur les permissions
EFFECTIVES, elle lui en montre moins — c'est voulu, un menu qui ne mène jamais à un refus.

⚠️ **Renommer un rôle dans le seeder ne le renomme PAS en base.** Spatie cherche les
rôles par leur nom : changer `'lecteur'` en `'utilisateur'` dans la référence a CRÉÉ un
second rôle, laissant l'ancien avec ses comptes et hors référence — donc jamais corrigé
par `syncPermissions`. Une migration
(`2026_09_21_180000_rename_role_lecteur_to_utilisateur`) déplace les comptes puis
supprime l'ancien. Le même piège vaudra pour tout renommage futur.

**Espace agent, sous-lot 3b** : `GET` et `POST /driver/leaves` (les pauses), et
`PATCH /auth/profile` (nom, téléphone, adresse — l'e-mail et la photo en sont exclus).

⚠️ Les routes de pauses ne portent **aucune** `permission:`, et c'est délibéré :
`view-leaves` et `create-leaves` sont les permissions d'ADMINISTRATION des pauses de tous
les agents, portées par `lecteur` et `admin`. En réutiliser une ici mêlerait « voir mes
pauses » et « gérer celles des autres ». `abilities:driver` garde l'espace, et la portée
vient de `Auth::user()->driver`.

⚠️ Les quatre refus de `DriverLeaveController::store()` étaient des
`redirect()->back()->with('error')`, donc invisibles pour une API. `RequestLeave` les
lève en `ApiException` — `LEAVE_NO_ACTIVE_CONTRACT`, `LEAVE_BEFORE_CONTRACT_START`,
`LEAVE_ALREADY_PENDING` — avec les messages repris mot pour mot, pour que le chemin Blade
affiche les mêmes flash.

**Espace agent, sous-lot 3a** (`routes/api/v1/driver.php`) : quatre lectures
(`bookings/available`, `bookings/assigned`, `bookings/history`, `dashboard`) et cinq
écritures (`bookings/{id}/accept|start|complete|cancel|revoke-subscription`), sous
`['token.fresh', 'auth:sanctum', 'abilities:driver']` + `permission:view-bookings` sur
les lectures et `edit-bookings` sur les écritures. Le vocabulaire reste `bookings`, jamais
« rides ».

⚠️ **Les routes Blade de l'agent ne portent aucune permission**, seulement
`profil:driver` : l'API est donc plus stricte. Le rôle `driver` de référence porte bien
les deux permissions, mais un agent réel à qui elles manqueraient perdrait l'accès.

⚠️ **Deux classes `Data` distinctes** pour une course selon qu'elle est disponible ou
acceptée. `AvailableBookingData` ne porte **aucune coordonnée client** — ni téléphone, ni
nom, ni demandes particulières, ni prix — parce que l'écran des courses disponibles n'en
affiche aucune. C'est une règle de confidentialité portée par la structure, pas par des
champs facultatifs : ne rien y ajouter de tout cela.

⚠️ **`pickup_at` part en ISO 8601 SANS décalage** (`2026-10-05T08:00:00`). `pickup_date` et
`pickup_time` décrivent une heure murale, pas un instant ; l'application tourne en UTC et
le Bénin est à UTC+1, donc suffixer un décalage déplacerait l'affichage d'une heure.
`started_at` et `completed_at`, eux, viennent de colonnes `timestamp` et gardent le leur.

**Authentification de l'API.** Jeton Bearer Sanctum nommé `api` — jamais du nom du
profil, car `AuthService::login()` (chemin Blade) supprime les jetons ainsi nommés et
tuerait les sessions du front Nuxt. Plafond absolu de 90 jours posé sur `expires_at` à la
création, et fenêtre d'inactivité de 14 jours appliquée par le middleware `token.fresh`
(`EnforceTokenFreshness`) sur le seul groupe de routes API : `config/sanctum.php` reste à
`'expiration' => null` pour ne pas toucher au Blade. Seuils dans `config/identity.php`.

⚠️ `token.fresh` s'exécute **avant** `auth:sanctum` et résout le jeton lui-même : le garde
de Sanctum écrit `last_used_at` à `now()` pendant qu'il authentifie, donc lu après lui ce
champ vaut toujours « à l'instant ». L'ordre est garanti par un
`prependToPriorityList()` dans `bootstrap/app.php` visant l'**interface**
`AuthenticatesRequests` — déclarer l'ordre sur la route ne suffit pas.

La connexion **ne demande pas le profil** : il est résolu en vérifiant le mot de passe
contre tous les comptes portant l'email (l'unicité est sur `(email, profil)`). Une
ambiguïté ressort en `409 PROFIL_AMBIGUOUS` avec la liste des profils, et le front
rappelle l'endpoint en précisant celui choisi. Les échecs incrémentent le compteur de
tous les comptes de l'email, mais un compte verrouillé est écarté des candidats au lieu
de faire échouer la requête entière — sinon s'acharner sur un compte bloquerait l'autre.

La réinitialisation de mot de passe s'appuie sur `password_reset_tokens` **réindexée par
`user_id`** (la table de Laravel, à clé primaire `email`, ne peut pas distinguer deux
comptes partageant une adresse). Un seul email est envoyé, avec un lien par compte ; le
jeton a la forme `{user_id}.{aléa}` pour rester résoluble tout en étant haché en base.
Les liens sont construits sur `FRONT_APP_URL`, qui a donc un second rôle au-delà du CORS.

`POST /public/bookings` est en plus protégée par **Cloudflare Turnstile**
(`App\Shared\Http\Middleware\VerifyTurnstile`, alias de middleware `turnstile`) : le
throttling seul ne protège pas un endpoint anonyme, puisque le partage d'IP des
opérateurs mobiles (CGNAT) interdit de serrer la limite. Le front envoie le champ
`cf_turnstile_token` ; un refus ressort en 422 sur ce champ. **Sans `TURNSTILE_SECRET`,
le middleware se retire** : le local et les tests ne le subissent pas, mais la
production doit impérativement avoir la variable. Si Cloudflare est injoignable, le
choix retenu est de laisser passer la réservation plutôt que de la perdre.

⚠️ Le formulaire Blade du landing recalcule la majoration horaire en JavaScript
(`pages/index.blade.php`), en recopiant les constantes de `Price` : toute modification
de la fenêtre horaire ou du montant doit être reportée dans les deux. L'API, elle,
renvoie le prix déjà majoré (`PriceQuoteData`) pour que le front n'ait rien à recalculer.

## Commandes artisan

- `app:expire-bookings` → marque expired les courses pending +24h dépassées
- `app:process-recurring-bookings` → crée les courses J+1 depuis les abonnements (cron à 1h)
- `app:generate-daily {--date=}` → génère les paiements journaliers (lun-ven uniquement)
- `app:activate-leave-pauses` → active les pauses véhicule automatiques liées aux pauses agent

## Scheduler (bootstrap/app.php → withSchedule)

Laravel 11 sans Kernel.php : le scheduler est déclaré directement dans `bootstrap/app.php`, pas dans routes/console.php.

| Commande                         | Fréquence                     |
| -------------------------------- | ----------------------------- |
| `app:expire-bookings`            | tous les jours à 01:00        |
| `app:process-recurring-bookings` | tous les jours à 01:00        |
| `app:generate-daily`             | lun-ven à 23:30 (`weekdays()`) |
| `app:activate-leave-pauses`      | toutes les 2 heures           |

Sortie ajoutée à `storage/logs/commands.log`. En conteneur, `schedule:run` est lancé chaque
minute par une boucle supervisord (`docker/supervisord.conf`), pas par un cron système.

## Déploiement (Coolify)

Image Docker construite depuis `Dockerfile` (build pack Dockerfile de Coolify) :
nginx + php-fpm + 2 workers `queue:work` + boucle scheduler, orchestrés par supervisord.
`docker/start.sh` attend PostgreSQL, puis lance **`migrate --force` à chaque démarrage**
et reconstruit les caches (config, routes, vues) — les variables d'environnement
n'existent pas au build. Une migration est donc exécutée en production dès que l'image
est déployée : elle doit être compatible avec l'image précédente (rollback).

## PWA & Firebase

- Service worker : /sw.js
- Firebase messaging SW : /firebase-messaging-sw.js
- Clés VAPID dans meta[name="firebase-vapid-key"]
- Token FCM sauvegardé dans table fcm_tokens
- Notifications envoyées via FcmNotificationService (kreait/laravel-firebase)
- Page d'installation : /install

## Vues importantes

- layouts/app.blade.php → layout admin/driver/owner avec sidebar
- layouts/auth.blade.php → layout login/install
- pages/admin/bookings/index → DataTables, colonne 0 cachée (timestamp tri)
- pages/admin/contracts/index → onglets Contrats agents / Contrats propriétaires
- pages/driver/bookings/available → courses disponibles avec logique visibilité
- pages/driver/bookings/accepting → courses actives avec label dynamique
- pages/client/owner/\* → espace propriétaire : index (véhicules), leaves, payments
  (sous client/, pas de dossier pages/owner). `OwnerVehicleController::show` rend
  pages.client.owner.vehicles.show, qui n'existe pas : la route owner.vehicles.show
  est orpheline, aucune vue n'y mène.

## Points d'attention

- ⚠️ **Aucune fabrique hors des tests.** `fakerphp/faker` est en `require-dev` et le
  `Dockerfile` déploie avec `composer install --no-dev` ; tout code qui l'atteint lève
  « Class "Faker\Factory" not found », depuis `DatabaseServiceProvider`.
  La règle n'est PAS « éviter `fake()` » — elle est plus forte :
  `Factory::__construct` fait `$this->faker = $this->withFaker()` **inconditionnellement**,
  donc **instancier** une fabrique résout `Faker\Generator`, que sa `definition()` appelle
  `fake()` ou non. Un seeder, une commande ou un contrôleur qui doit tourner ailleurs
  qu'en développement crée ses données par `Model::create()`.
  Vérifié le 2026-09-18 en deux temps : retirer `fake()` des définitions n'a PAS suffi.
  `DriverScenarioSeederTest` pose un piège dans le conteneur — toute résolution de
  `Faker\Generator` y lève — et c'est le seul test qui reproduise la panne ; les
  vérifications statiques du source, elles, passaient déjà.
- ⚠️ **PostgreSQL est strict sur le type `uuid`.** Comparer une colonne `uuid` à une
  chaîne qui n'en est pas un ne rend pas « aucun résultat » : cela lève
  `invalid input syntax for type uuid` et fait échouer la requête ENTIÈRE, y compris les
  clauses `orWhere` qui auraient trouvé. Tester la forme avant (`Str::isUuid()`) plutôt
  que de laisser le moteur trancher. Même origine que le piège du `CONCAT` sur les dates :
  ce qui marcherait en MySQL casse ici.
- UUID partout (HasUuid trait), keyType=string, incrementing=false
- PostgreSQL : pas de CONCAT pour dates → (pickup_date::date + pickup_time::time).
  ⚠️ `Driver::hasBlockingPreviousBookings()` violait cette règle et en a payé le prix :
  elle comparait un `CONCAT` SQL à une chaîne PHP bâtie depuis un Carbon, dont la
  conversion glisse « 00:00:00 » entre la date et l'heure. Aucune course antérieure du
  même jour n'était vue comme antérieure. Corrigé le 2026-09-18 — la comparaison porte
  désormais sur des timestamps. Le piège vaut pour **toute** comparaison qui mélange une
  expression SQL et une valeur construite en PHP.
- Spatie : tous les rôles sous guard 'web', model_id en uuid dans model_has_roles
- DataTables : colonne 0 cachée avec timestamp pour tri, type:'num' dans columnDefs
- Sanctum + session : Auth::login() obligatoire en plus de createToken()
- Paiements : commission/driver_earning calculés à la completion, pas à la création
- Pauses : pas de restriction de dépassement, surplus affiché en rouge
- ⚠️ **`LeaveRequestFactory` pose `effective_days` par défaut**, cohérent avec son état
  par défaut `completed`. Une demande `pending` ou `ongoing` n'en a PAS : utiliser les
  états `pending()` et `ongoing()`, qui les remettent à null. Les calculs de solde lisent
  `effective_days ?? requested_days`, donc un résidu fausse silencieusement le solde —
  piège payé le 2026-09-22, sur un test qui attendait 12 et trouvait 9.
- ⚠️ **Les compteurs de pauses sont PAR CONTRAT.** `DriverContractService` remet
  `leave_days_used` à zéro à la clôture d'un contrat, et le droit annoncé vaut
  `2 × contract_months` du contrat courant : les pauses d'un contrat précédent ne doivent
  donc pas grever le solde du nouveau. `getLeaveRequestsByStatus()` filtre sur le contrat
  actif depuis le 2026-09-21 et rend 0 quand il n'y en a pas.

  Trois défauts corrigés ce jour-là, tous visibles sur le même écran d'un agent réel qui
  affichait `total 48 / utilisés 0 / disponibles -5` avec cinq jours dans son historique :

  1. `getContractMonths()` retombait sur 24 mois **même sans contrat actif**, donc l'écran
     annonçait 48 jours de droits à quelqu'un qui n'a pas de contrat ;
  2. `leave_days_used` était lu depuis la COLONNE, jamais mise à jour sur ces agents-là :
     « Jours utilisés : 0 » s'affichait au-dessus de l'historique qui le démentait.
     `getLeaveDaysTaken()` recompte depuis les pauses terminées ;
  3. les pauses d'un contrat clos se déduisaient du solde courant, d'où le `-5`.

  ⚠️ Un « Disponibles à date » NÉGATIF n'est pas un défaut : le dépassement est permis, et
  le négatif dit « vous avez pris d'avance sur votre acquisition ». Ne pas le ramener à 0.

  ⚠️ `leave_days_used` n'est plus la source de vérité des jours pris. La colonne est
  toujours écrite par `markLeaveDaysUsed()` mais ne doit plus être lue directement.

- ⚠️ **Deux routes orphelines retirées le 2026-09-21** : `client.payments.history` et
  `client.leaves.history` pointaient vers des méthodes inexistantes de
  `DashboardController` et répondaient donc 500. La seconde exigeait `view-leaves`, la
  permission d'administration des pauses de TOUS les agents : la garder aurait poussé à
  l'accorder au rôle `client` pour « rendre la route cohérente ».

## Notifications — l'état réel trouvé le 2026-09-21

À l'ouverture du sous-lot 3c, la lecture du code a révélé que **la chaîne de
notification n'a jamais fonctionné**. Trois défauts indépendants se masquaient l'un
l'autre :

1. ⚠️ **`FcmToken` n'avait pas le trait `HasUuid`** alors que sa clé primaire est un
   `uuid` NOT NULL sans défaut. Eloquent croyait la clé auto-incrémentée, omettait la
   colonne, et PostgreSQL refusait la ligne : `POST /fcm/token` levait une
   `QueryException` à **chaque** appel. Aucun appareil n'a donc jamais été enregistré, et
   le seul déclencheur de l'application — une réservation créée prévient les agents —
   parcourait toujours une liste vide.
2. ⚠️ **Rien n'écrivait jamais dans `notifications`.** La table existe depuis janvier et
   la cloche de l'en-tête la lit, mais aucune ligne de code n'y insérait quoi que ce
   soit.
3. ⚠️ **`notification_preferences` n'était lu par personne.** L'écran de réglages
   écrivait `push_notifications: false` et l'envoi ne le consultait pas.

Les trois sont corrigés, avec les tests qui les reproduisent (`tests/Feature/Notification/`).

⚠️ **Une préférence ABSENTE vaut ACCORD**, à l'envoi comme à l'affichage.
`notification_preferences` vaut `{}` pour tous les comptes existants : lire l'absence
comme un refus couperait les notifications de toute la flotte en un déploiement. Seul un
`false` explicite désactive. `FcmNotificationService::acceptePush()` et
`NotificationPreferencesData::fromUser()` doivent rester d'accord là-dessus.

La préférence porte sur le **push seul** : la trace en base est écrite dans tous les cas,
pour que refuser les alertes système ne revienne pas à se priver de l'information dans
l'application.

**Les routes `/api/v1/notifications/*` ne portent ni `abilities:` ni `permission:`**, et
c'est délibéré : c'est toute l'application — agent, client, admin, propriétaire — qui est
installée et notifiée. La portée vient de `Auth::user()`, comme pour `/auth/me`.

⚠️ **`notifications.id` est un ENTIER auto-incrémenté**, seule exception aux uuid du
projet : la table date de janvier. Le front doit le typer en `number`.

## Qui est prévenu de quoi

**Tout passe par `App\Domains\Notification\Application\Notifier`**, qui porte la table
de routage. Ne JAMAIS appeler `FcmNotificationService` directement depuis une action :
éparpiller les envois rendrait impossible de répondre à « qui reçoit quoi ? » autrement
qu'en relisant le dépôt entier.

| Événement | Destinataire |
|---|---|
| Nouvelle réservation | tous les agents |
| Course acceptée / démarrée / terminée / annulée / révoquée | les administrateurs |
| Demande de pause déposée | les administrateurs |
| Pause validée / refusée | l'agent concerné |
| Véhicule mis en pause / reprise | le propriétaire du véhicule |
| Paiement **validé** ou **annulé** | l'agent concerné, lui seul |

⚠️ « Les administrateurs sont prévenus de toutes les actions agent » ne veut PAS dire
qu'ils reçoivent tout : une pause validée ne leur revient pas, puisque c'est l'un d'eux
qui vient de la valider.

⚠️ **C'est l'ACTION sur un paiement qui est notifiée, jamais sa création.** La création
est majoritairement automatique — `generateDailyPaymentForContract()` tourne chaque soir
du lundi au vendredi pour chaque contrat — et la notifier enverrait à chaque agent une
alerte quotidienne perpétuelle pour une écriture sur laquelle il n'a rien à faire. La
validation et l'annulation, elles, sont des gestes d'administrateur qui changent quelque
chose pour l'agent.

**Pour câbler un nouvel événement**, suivre `docs/ajouter-une-notification.md`.

⚠️ **Les notifications d'administrateur ne portent pas d'URL** tant que l'espace admin du
front Nuxt n'est pas migré : une destination vers un écran inexistant est pire qu'aucune
destination. Les chemins futurs sont en commentaire à chaque appel.

⚠️ **Les notifications partent APRÈS la transaction, jamais dedans.** Dedans, un push
serait envoyé pour une opération qu'un `rollback` annulerait ensuite. Et `Notifier`
n'échoue jamais bruyamment : une notification est un effet de bord, elle ne doit pas
pouvoir empêcher d'accepter une course ou d'enregistrer un paiement. Les échecs vont au
journal.

Deux fichiers de tests, et il faut les deux : `NotificationRoutingTest` vérifie la table
en isolant le `Notifier` — surtout par des assertions NÉGATIVES, un routage trop large ne
casse rien de visible mais noie les destinataires — et `NotificationHooksTest` vérifie que
les actions l'appellent réellement, ce que le premier ne dit pas.

## ⚠️ Le garde d'authentification est mémorisé entre deux requêtes de test

Le garde de Sanctum mémorise l'utilisateur qu'il a résolu, et l'instance survit d'une
requête de test à l'autre **dans une même méthode**. Sans `Auth::forgetGuards()` avant de
changer de jeton, la deuxième requête s'exécute encore sous le PREMIER compte, quel que
soit l'en-tête envoyé.

Le symptôme fabrique des **faux positifs** : un test de portée « un compte ne touche pas
les données d'un autre » passe sans rien prouver, puisque les deux requêtes viennent en
réalité du même compte. Le problème n'existe pas en production, où chaque requête part
d'un conteneur neuf.

Tout test qui authentifie deux comptes doit appeler `Auth::forgetGuards()` entre les
deux — voir le helper `entete()` de `NotificationsApiTest`.

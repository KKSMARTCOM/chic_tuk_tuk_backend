<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Alias stables des modèles pour les colonnes polymorphes.
     *
     * Ces alias sont écrits en base à la place du FQCN (model_has_roles.model_type,
     * model_has_permissions.model_type, personal_access_tokens.tokenable_type).
     * Ils découplent le schéma du namespace PHP : les classes peuvent être déplacées
     * (migration vers une architecture DDD) sans invalider les rôles, les permissions
     * ni les tokens Sanctum existants.
     *
     * ⚠️ Un alias déjà écrit en base ne doit JAMAIS être renommé ni supprimé sans
     * migration de données correspondante. Cf. la migration
     * 2026_09_15_100000_rewrite_morph_types_to_aliases.
     */
    private const MORPH_MAP = [
        'booking'          => \App\Models\Booking::class,
        'commission'       => \App\Models\Commission::class,
        'driver'           => \App\Models\Driver::class,
        'driver_contract'  => \App\Models\DriverContract::class,
        'fcm_token'        => \App\Models\FcmToken::class,
        'leave_request'    => \App\Models\LeaveRequest::class,
        'notification'     => \App\Models\Notification::class,
        'payment'          => \App\Models\Payment::class,
        'permission'       => \App\Models\Permission::class,
        'pricing'          => \App\Models\Pricing::class,
        'promo_code'       => \App\Models\PromoCode::class,
        'role'             => \App\Models\Role::class,
        'testimonial'      => \App\Models\Testimonial::class,
        'tourist_circuit'  => \App\Models\TouristCircuit::class,
        'user'             => \App\Models\User::class,
        'vehicle'          => \App\Models\Vehicle::class,
        'vehicle_contract' => \App\Models\VehicleContract::class,
        'vehicle_pause'    => \App\Models\VehiclePause::class,
        'zone'             => \App\Models\Zone::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // enforce* : lever une exception si un modèle polymorphe n'est pas déclaré,
        // plutôt que de réintroduire silencieusement un FQCN en base.
        Relation::enforceMorphMap(self::MORPH_MAP);

        if (env(key: 'APP_ENV') !== 'local') {
            URL::forceScheme(scheme: 'https');
        }

        $this->registerRateLimiters();

        // ⚠️ Un jeton ne vaut que si son compte est ACTIF, vérifié à CHAQUE requête. Jusqu'au
        // 2026-09-26, seule la connexion regardait `is_active` : un jeton déjà émis survivait
        // à la désactivation du compte jusqu'à son expiration (14 jours d'inactivité, 90 au
        // plus). `$isValid` porte les contrôles de Sanctum lui-même, qu'on conserve. Seul un
        // `false` explicite désactive : la colonne est NOT NULL DEFAULT true.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid
                && $token->tokenable !== null
                && $token->tokenable->is_active !== false
        );
    }

    /**
     * Limiteurs nommés, un par route throttlée.
     *
     * ⚠️ Indispensable, et non cosmétique. Le throttle anonyme de Laravel partage un
     * compteur unique par IP entre TOUTES les routes : pour une requête sans
     * utilisateur, `ThrottleRequests::resolveRequestSignature()` renvoie
     * `sha1($route->getDomain().'|'.$request->ip())`, sans la route ni l'URI, et la
     * clé finale vaut ce hachage préfixé d'une chaîne vide.
     *
     * Les conséquences étaient réelles, observées sur staging le 2026-09-17 :
     *
     * - Le plafond de chaque route se compare à un compteur commun. Épuiser le devis
     *   public (60/min) bloquait la connexion (30/min) et la réservation (10/h).
     * - La fenêtre de la route qui crée le minuteur gouverne le blocage de toutes les
     *   autres. `public/bookings` étant à 10 par heure, dix réservations anonymes
     *   depuis une IP d'opérateur — qui couvre de nombreux abonnés au Bénin — coupaient
     *   la connexion des agents pendant une heure.
     *
     * Un limiteur nommé résout cela parce que sa clé est `md5($nom.$cle)` : le nom du
     * limiteur entre dans la clé, donc chaque route a son propre compteur. La clé
     * reste préfixée du nom côté appelant pour que ce soit lisible dans le cache.
     *
     * Couvert par `tests/Feature/Identity/ThrottleIsolationTest.php`, qui épuise
     * volontairement la route au plafond le PLUS HAUT avant d'éprouver une route au
     * plafond plus bas — l'inverse passe avec un compteur partagé et ne prouve rien.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(30)
            ->by('auth-login|'.$request->ip()));

        // Un seul compteur pour « mot de passe oublié » et « réinitialiser » : c'est
        // le même parcours utilisateur, et les isoler n'apporterait rien.
        RateLimiter::for('auth-password', fn (Request $request) => Limit::perMinute(30)
            ->by('auth-password|'.$request->ip()));

        RateLimiter::for('public-quote', fn (Request $request) => Limit::perMinute(60)
            ->by('public-quote|'.$request->ip()));

        // La seule route volontairement serrée : elle crée des données. Turnstile la
        // protège en plus. Son isolement est justement ce qui compte le plus ici.
        RateLimiter::for('public-bookings', fn (Request $request) => Limit::perHour(10)
            ->by('public-bookings|'.$request->ip()));
    }
}

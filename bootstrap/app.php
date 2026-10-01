<?php

use App\Http\Middleware\CheckPermission;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\EnforceTokenFreshness;
use App\Shared\Http\Middleware\VerifyTurnstile;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Derrière Traefik (Coolify) : sans ceci, Laravel lit l'IP du proxy au lieu de
        // celle du client, ce qui fausse le rate limiting par IP.
        $middleware->trustProxies(at: '*');

        // Laravel ne sert plus que l'API (2026-09-27) : un invité n'est redirigé nulle
        // part. Sans ceci, le middleware d'authentification appelle route('login'), qui
        // n'existe plus, et une 401 devient une 500.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'permission' => CheckPermission::class,
            'turnstile' => VerifyTurnstile::class,
            'token.fresh' => EnforceTokenFreshness::class,
            // Sanctum pose le profil comme ability sur le jeton (AuthenticateUser :
            // `abilities: [$user->profil]`). Cet alias permet de garder un espace par
            // l'ability plutôt que par la colonne `users.profil` : la restriction voyage
            // alors avec la crédence elle-même, et un jeton d'administrateur reste
            // dehors même si on lui attribuait par erreur les permissions `view-own-*`.
            //
            // Le refus lève MissingAbilityException, qui hérite d'AuthorizationException
            // et qu'ApiExceptionRenderer traduit déjà en 403 FORBIDDEN — vérifié dans le
            // code du paquet, pas supposé.
            'abilities' => CheckAbilities::class,
        ]);

        // EnforceTokenFreshness doit s'exécuter AVANT l'authentification : le garde de
        // Sanctum écrit `last_used_at` à now() pendant qu'il authentifie, et lu après
        // lui ce champ vaut toujours « à l'instant » — la fenêtre d'inactivité
        // n'expirerait jamais personne. Le déclarer avant `auth:sanctum` sur la route
        // ne suffit pas : l'authentification figure dans la liste de priorité de
        // Laravel et s'y trouve hissée devant tout middleware qui n'y figure pas.
        // prependToPriorityList insère dans cette liste sans la remplacer, et ne
        // change donc l'ordre que des piles contenant ce middleware.
        //
        // ⚠️ Le repère est l'INTERFACE AuthenticatesRequests, et non la classe
        // concrète Authenticate : c'est l'interface qui figure dans la liste. Viser la
        // classe ne correspond à rien, et le middleware se retrouve silencieusement
        // relégué en fin de liste, donc après l'authentification.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnforceTokenFreshness::class,
        );
    })
    ->withSchedule(function ($schedule) {
        $schedule->command('app:expire-bookings')->dailyAt('01:00')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:process-recurring-bookings')->dailyAt('01:00')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:generate-daily')->weekdays()->dailyAt('23:30')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:activate-leave-pauses')->everyTwoHours()->appendOutputTo(storage_path('logs/commands.log'));
        // Les brouillons des fiches de rémunération du mois écoulé (spec 2026-09-30).
        $schedule->command('app:generate-remuneration-statements')->monthlyOn(1, '02:00')->appendOutputTo(storage_path('logs/commands.log'));
        // Les PDF des fiches vivent un an (spec 2026-10-01, §7.2).
        $schedule->command('app:purge-remuneration-pdfs')->dailyAt('03:00')->appendOutputTo(storage_path('logs/commands.log'));
        // Le journal d'activité garde 12 mois (`config/activitylog.php`).
        $schedule->command('activitylog:clean --force')->dailyAt('02:00')->appendOutputTo(storage_path('logs/commands.log'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Laravel ne sert plus que l'API (2026-09-27) : toute réponse d'erreur est du JSON,
        // y compris pour une requête qui n'annonce pas `Accept: application/json`.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, (bool) config('app.debug')));
    })->create();

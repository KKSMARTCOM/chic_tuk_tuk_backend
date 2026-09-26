<?php

use App\Shared\Http\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Derrière Traefik (Coolify) : sans ceci, Laravel lit l'IP du proxy au lieu de
        // celle du client, ce qui fausse le rate limiting par IP d'AuthService.
        $middleware->trustProxies(at: '*');

        $middleware->prepend(\App\Http\Middleware\InjectSanctumTokenFromCookie::class);

        // Une session Blade ne survit pas à la désactivation de son compte (2026-09-26).
        $middleware->appendToGroup('web', \App\Shared\Http\Middleware\EndDisabledAccountSession::class);

        $middleware->alias([
            'guest'         => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'role'          => \App\Http\Middleware\CheckRole::class,
            'permission'    => \App\Http\Middleware\CheckPermission::class,
            'profil'        => \App\Http\Middleware\CheckProfil::class,
            'turnstile'     => \App\Shared\Http\Middleware\VerifyTurnstile::class,
            'token.fresh'   => \App\Shared\Http\Middleware\EnforceTokenFreshness::class,
            // Sanctum pose le profil comme ability sur le jeton (AuthenticateUser :
            // `abilities: [$user->profil]`). Cet alias permet de garder un espace par
            // l'ability plutôt que par la colonne `users.profil` : la restriction voyage
            // alors avec la crédence elle-même, et un jeton d'administrateur reste
            // dehors même si on lui attribuait par erreur les permissions `view-own-*`.
            //
            // Le refus lève MissingAbilityException, qui hérite d'AuthorizationException
            // et qu'ApiExceptionRenderer traduit déjà en 403 FORBIDDEN — vérifié dans le
            // code du paquet, pas supposé.
            'abilities'     => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);

        // EnforceTokenFreshness doit s'exécuter AVANT l'authentification : le garde de
        // Sanctum écrit `last_used_at` à now() pendant qu'il authentifie, et lu après
        // lui ce champ vaut toujours « à l'instant » — la fenêtre d'inactivité
        // n'expirerait jamais personne. Le déclarer avant `auth:sanctum` sur la route
        // ne suffit pas : l'authentification figure dans la liste de priorité de
        // Laravel et s'y trouve hissée devant tout middleware qui n'y figure pas.
        // prependToPriorityList insère dans cette liste sans la remplacer, et ne
        // change donc l'ordre que des piles contenant ce middleware — le chemin Blade
        // n'est pas affecté.
        //
        // ⚠️ Le repère est l'INTERFACE AuthenticatesRequests, et non la classe
        // concrète Authenticate : c'est l'interface qui figure dans la liste. Viser la
        // classe ne correspond à rien, et le middleware se retrouve silencieusement
        // relégué en fin de liste, donc après l'authentification.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Shared\Http\Middleware\EnforceTokenFreshness::class,
        );
    })
    ->withSchedule(function ($schedule) {
        $schedule->command('app:expire-bookings')->dailyAt('01:00')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:process-recurring-bookings')->dailyAt('01:00')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:generate-daily')->weekdays()->dailyAt('23:30')->appendOutputTo(storage_path('logs/commands.log'));
        $schedule->command('app:activate-leave-pauses')->everyTwoHours()->appendOutputTo(storage_path('logs/commands.log'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            // ----------------------------------------------------------------
            // API : toujours du JSON, jamais de destruction de session.
            // ----------------------------------------------------------------
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiExceptionRenderer::render($e, (bool) config('app.debug'));
            }

            // ----------------------------------------------------------------
            // Web (Blade) : purge de session, restreinte aux cas légitimes.
            //
            // Historiquement déclenchée sur toute HttpException hors 404/500, ce qui
            // déconnectait l'utilisateur sur une simple 403 (permission refusée) ou
            // une 429 (rate limit). Seules une authentification absente (401) ou une
            // session expirée (419) justifient de révoquer les jetons.
            // ----------------------------------------------------------------
            if ($e instanceof HttpException) {
                $code = $e->getStatusCode();

                if (in_array($code, [401, 419], true)) {
                    foreach (['admin', 'driver', 'client', 'owner'] as $profil) {
                        $cookieName = 'ctt_' . $profil . '_token';

                        if ($rawToken = $request->cookie($cookieName)) {
                            PersonalAccessToken::findToken($rawToken)?->delete();
                            Cookie::queue(Cookie::forget($cookieName));
                        }
                    }

                    // Seul le guard "web" (session PHP) supporte logout().
                    if (Auth::guard('web')->check()) {
                        Auth::guard('web')->logout();
                    }

                    try {
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
                    } catch (\Exception $sessionException) {
                        // La session peut déjà être invalide selon le contexte.
                    }
                }

                if (view()->exists("errors.$code")) {
                    return response()->view("errors.$code", [], $code);
                }
            }

            return null;
        });
    })->create();

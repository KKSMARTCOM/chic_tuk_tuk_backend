<?php

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme la session Blade d'un compte désactivé, dès sa requête suivante.
 *
 * ⚠️ Seule la connexion vérifiait `is_active` : une session ouverte survivait à la
 * désactivation du compte (corrigé le 2026-09-26). Le garde de Sanctum consulte la
 * session web AVANT tout jeton, si bien que le contrôle posé sur les jetons
 * (`AppServiceProvider`) ne suffit pas au chemin Blade. Ce middleware est ajouté au
 * groupe `web` : il couvre les quatre espaces sans toucher à leurs routes.
 */
final class EndDisabledAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        // `=== false` et non `! is_active` : la colonne est NOT NULL DEFAULT true, et un
        // modèle créé sans l'attribut ne le porte pas en mémoire — il est pourtant actif.
        if ($user !== null && $user->is_active === false) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Votre compte a été désactivé. Contactez l\'administrateur.');
        }

        return $next($request);
    }
}

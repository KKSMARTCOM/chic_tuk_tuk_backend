<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$permissions): Response
    {
        $user = auth()->user();

        if (! $user) {
            // Plus de page de connexion à laquelle renvoyer (2026-09-27) : 401 JSON.
            throw new AuthenticationException;
        }

        // Check if user has any of the required permissions
        if (! $user->hasAnyPermission($permissions)) {
            abort(403, 'Accès non autorisé. Permission insuffisante.');
        }

        return $next($request);
    }
}

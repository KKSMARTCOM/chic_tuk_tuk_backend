<?php

namespace App\Domains\Identity\Presentation\Api\V1;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Identity\Application\Actions\AuthenticateUser;
use App\Domains\Identity\Application\Actions\UpdateProfile;
use App\Domains\Identity\Application\Data\LoginData;
use App\Domains\Identity\Application\Data\UpdateProfileData;
use App\Domains\Identity\Application\Data\UserData;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chaque méthode attrape ses propres échecs, comme les contrôleurs Blade du projet,
 * mais renvoie du JSON.
 *
 * ⚠️ Deux règles à respecter dans chacun de ces try/catch :
 *
 *   1. `ValidationException` et `ApiException` sont relancées EN PREMIER. Elles sont la
 *      réponse voulue de l'API (422 d'identifiants, 423 de verrou, 409 d'ambiguïté,
 *      403 de compte désactivé) et `ApiExceptionRenderer` sait déjà les rendre. Les
 *      attraper avec le reste transformerait un refus légitime en 500.
 *   2. Le message de l'exception va au JOURNAL, jamais dans la réponse. Contrairement
 *      au chemin Blade où il finit en message flash, ici il part au client, et il
 *      contient régulièrement un fragment SQL ou un chemin de fichier.
 *   3. On attrape `\Throwable` et non `\Exception` : les `\Error` (TypeError,
 *      ValueError, DivisionByZeroError) n'héritent pas d'`Exception` et passeraient
 *      sous le nez du catch pour ressortir en `SERVER_ERROR` générique.
 */
final class AuthController
{
    public function login(LoginData $data, Request $request, AuthenticateUser $authenticate, ActivityJournal $journal): JsonResponse
    {
        try {
            $issued = $authenticate($data, (string) $request->ip(), $request->userAgent());
            $journal->loggedIn($issued->user);

            return response()->json([
                'token' => $issued->plainTextToken,
                'user' => UserData::fromModel($issued->user),
            ]);
        } catch (ValidationException $e) {
            $journal->loginFailed($data->email, 'bad_credentials');

            throw $e;
        } catch (ApiException $e) {
            // Un 409 PROFIL_AMBIGUOUS n'est pas un refus : le mot de passe était bon, il
            // manque le profil, et la connexion suivante sera tracée.
            $reason = match ($e->errorCode) {
                'ACCOUNT_LOCKED' => 'locked',
                'ACCOUNT_DISABLED' => 'disabled',
                default => null,
            };
            if ($reason) {
                $journal->loginFailed($data->email, $reason);
            }

            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la connexion : '.$e->getMessage(), [
                'exception' => $e,
                'email' => $data->email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'La connexion a échoué pour une raison technique. Réessayez dans un instant.',
                'code' => 'LOGIN_FAILED',
            ], 500);
        }
    }

    public function logout(Request $request, ActivityJournal $journal): Response
    {
        try {
            // Seul le jeton courant : les autres appareils restent connectés.
            $request->user()->currentAccessToken()->delete();
            $journal->loggedOut($request->user());

            return response()->noContent();
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la déconnexion : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'La déconnexion a échoué pour une raison technique. Réessayez.',
                'code' => 'LOGOUT_FAILED',
            ], 500);
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            return response()->json(UserData::fromModel($request->user()));
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la lecture du profil courant : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Votre profil n\'a pas pu être chargé. Réessayez.',
                'code' => 'PROFILE_READ_FAILED',
            ], 500);
        }
    }

    /**
     * PATCH /auth/profile — nom, téléphone, adresse.
     *
     * Placée ici et non sous un espace : c'est l'utilisateur qu'on modifie, pas l'agent.
     * Le propriétaire et l'administrateur en auront besoin aussi. La LECTURE ne coûte
     * rien de plus — `/auth/me` renvoie déjà ces champs.
     *
     * L'e-mail et la photo n'en font pas partie : voir UpdateProfileData.
     */
    public function updateProfile(UpdateProfileData $data, Request $request, UpdateProfile $update): JsonResponse
    {
        try {
            return response()->json(UserData::fromModel($update($request->user(), $data)));
        } catch (ValidationException|ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur lors de la mise à jour du profil : '.$e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Votre profil n\'a pas pu être mis à jour. Réessayez.',
                'code' => 'PROFILE_UPDATE_FAILED',
            ], 500);
        }
    }
}

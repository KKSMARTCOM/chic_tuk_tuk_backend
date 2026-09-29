<?php

namespace App\Domains\Identity\Presentation\Api\V1;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Identity\Application\Actions\ListDeviceSessions;
use App\Domains\Identity\Application\Actions\RevokeDeviceSession;
use App\Domains\Identity\Application\Actions\RevokeOtherDeviceSessions;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les appareils connectés au compte : les lister, en couper un, couper tous les autres.
 *
 * Comme `/auth/me`, ces routes servent les quatre espaces et ne portent aucune
 * `permission:` : la portée vient de l'utilisateur authentifié.
 *
 * Mêmes règles de try/catch que `AuthController` : ValidationException, ApiException
 * et ModelNotFoundException relancées EN PREMIER, `\Throwable` et non `\Exception`, et
 * le message d'exception au journal seulement.
 */
final class DeviceSessionController
{
    public function index(Request $request, ListDeviceSessions $list): JsonResponse
    {
        try {
            return response()->json($list($this->user($request)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la lecture des appareils connectés',
                'La liste des appareils n\'a pas pu être chargée. Réessayez.', 'SESSIONS_READ_FAILED');
        }
    }

    public function destroy(Request $request, int $id, RevokeDeviceSession $revoke, ActivityJournal $journal): Response
    {
        try {
            $userAgent = $revoke($this->user($request), $id);
            $journal->sessionRevoked($this->user($request), $userAgent);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la déconnexion d\'un appareil',
                'Cet appareil n\'a pas pu être déconnecté. Réessayez.', 'SESSION_REVOKE_FAILED');
        }
    }

    public function logoutOthers(Request $request, RevokeOtherDeviceSessions $revoke, ActivityJournal $journal): Response
    {
        try {
            $revoke($this->user($request));
            $journal->otherSessionsRevoked($this->user($request));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la déconnexion des autres appareils',
                'Les autres appareils n\'ont pas pu être déconnectés. Réessayez.', 'SESSIONS_REVOKE_FAILED');
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function failure(\Throwable $e, Request $request, string $context, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$context} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}

<?php

namespace App\Domains\Notification\Presentation\Api\V1;

use App\Domains\Notification\Application\Actions\ForgetDevice;
use App\Domains\Notification\Application\Actions\ListNotifications;
use App\Domains\Notification\Application\Actions\MarkNotificationRead;
use App\Domains\Notification\Application\Actions\RegisterDevice;
use App\Domains\Notification\Application\Actions\UpdateNotificationPreferences;
use App\Domains\Notification\Application\Data\DeviceTokenData;
use App\Domains\Notification\Application\Data\NotificationPageData;
use App\Domains\Notification\Application\Data\NotificationPreferencesData;
use App\Domains\Notification\Application\Data\UpdatePreferencesData;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Notifications : appareils, cloche, préférences.
 *
 * ⚠️ Ces routes servent les QUATRE espaces — agent, client, admin, propriétaire — parce
 * que c'est toute l'application qui est installée et notifiée. Elles ne portent donc ni
 * `abilities:` ni `permission:` : la portée vient de `Auth::user()`, comme pour le profil.
 *
 * Mêmes règles de try/catch que les sous-lots 3a et 3b : ValidationException,
 * ApiException et ModelNotFoundException relancées EN PREMIER, `\Throwable` et non
 * `\Exception`, et le message d'exception au journal seulement.
 */
final class NotificationController
{
    public function index(Request $request, ListNotifications $lister): JsonResponse
    {
        try {
            [$page, $nonLues] = $lister(
                $this->utilisateur($request),
                max(1, (int) $request->query('page', 1)),
            );

            return response()->json(NotificationPageData::fromPaginator($page, $nonLues));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la lecture des notifications',
                'Vos notifications n\'ont pas pu être chargées. Réessayez.', 'NOTIFICATIONS_READ_FAILED');
        }
    }

    public function markRead(Request $request, int $id, MarkNotificationRead $marquer): Response
    {
        try {
            $marquer($this->utilisateur($request), $id);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le marquage d\'une notification',
                'Cette notification n\'a pas pu être marquée comme lue.', 'NOTIFICATION_READ_FAILED');
        }
    }

    public function markAllRead(Request $request, MarkNotificationRead $marquer): Response
    {
        try {
            $marquer->tout($this->utilisateur($request));

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le marquage de toutes les notifications',
                'Vos notifications n\'ont pas pu être marquées comme lues.', 'NOTIFICATIONS_READ_ALL_FAILED');
        }
    }

    public function registerDevice(Request $request, DeviceTokenData $data, RegisterDevice $enregistrer): Response
    {
        try {
            $enregistrer($this->utilisateur($request), $data->token);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'l\'enregistrement d\'un appareil',
                'Cet appareil n\'a pas pu être enregistré.', 'DEVICE_REGISTER_FAILED');
        }
    }

    public function forgetDevice(Request $request, DeviceTokenData $data, ForgetDevice $oublier): Response
    {
        try {
            $oublier($this->utilisateur($request), $data->token);

            return response()->noContent();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'le retrait d\'un appareil',
                'Cet appareil n\'a pas pu être retiré.', 'DEVICE_FORGET_FAILED');
        }
    }

    public function preferences(Request $request): JsonResponse
    {
        try {
            return response()->json(NotificationPreferencesData::fromUser($this->utilisateur($request)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la lecture des préférences',
                'Vos préférences n\'ont pas pu être chargées.', 'PREFERENCES_READ_FAILED');
        }
    }

    public function updatePreferences(
        Request $request,
        UpdatePreferencesData $data,
        UpdateNotificationPreferences $modifier,
    ): JsonResponse {
        try {
            $user = $modifier($this->utilisateur($request), $data);

            return response()->json(NotificationPreferencesData::fromUser($user));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->echec($e, $request, 'la modification des préférences',
                'Vos préférences n\'ont pas pu être enregistrées.', 'PREFERENCES_UPDATE_FAILED');
        }
    }

    private function utilisateur(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * Journalise avec son contexte et renvoie un code propre à l'opération.
     *
     * ⚠️ Le message de l'exception va au JOURNAL seulement : il contient régulièrement
     * un fragment SQL ou un chemin de fichier, et partirait sinon au client.
     */
    private function echec(\Throwable $e, Request $request, string $quoi, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$quoi} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}

<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * L'envoi des notifications : une trace en base, et un push quand l'utilisateur en veut.
 *
 * Trois défauts avérés ont été corrigés ici le 2026-09-21, à l'ouverture du sous-lot 3c.
 * Ils se masquaient l'un l'autre, et aucun ne se voyait depuis l'application.
 *
 *  1. **Rien n'écrivait jamais dans `notifications`.** La table existe depuis janvier et
 *     la cloche de l'en-tête la lit, mais aucune ligne de code n'y insérait quoi que ce
 *     soit : elle affichait « aucune notification » à tout le monde, toujours.
 *
 *  2. **`notification_preferences` n'était lu par personne.** L'écran de réglages
 *     écrivait `push_notifications: false`, et l'envoi ne le consultait pas. La case à
 *     cocher était décorative.
 *
 *  3. La façade `Firebase` était appelée statiquement, donc aucun test ne pouvait
 *     observer ce qui partait. Le contrat se résout désormais depuis le conteneur —
 *     paresseusement, pour qu'un environnement sans identifiants Firebase n'échoue pas
 *     à la simple construction du service.
 */
class FcmNotificationService
{
    /**
     * Envoie à un utilisateur : la trace en base d'abord, le push ensuite.
     *
     * L'ORDRE compte. La trace est ce que l'utilisateur retrouvera en ouvrant
     * l'application ; le push n'est qu'une alerte opportuniste, qui échoue dès qu'un
     * appareil est hors ligne, qu'une permission a été retirée ou que Firebase est
     * injoignable. Écrire la trace après le push la perdrait précisément dans les cas
     * où elle est la plus utile.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $body,
            'type' => $data['type'] ?? 'info',
            'is_read' => false,
            'data' => $data,
        ]);

        if (! $this->acceptePush($user)) {
            return;
        }

        $this->pousser($user, $title, $body, $data);
    }

    /**
     * Envoie à tous les agents.
     *
     * `with('fcmTokens')` évite une requête par agent : la flotte est petite aujourd'hui,
     * mais cette méthode est appelée à chaque réservation créée.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToDrivers(string $title, string $body, array $data = []): void
    {
        User::where('profil', 'driver')
            ->with('fcmTokens')
            ->get()
            ->each(fn (User $driver) => $this->sendToUser($driver, $title, $body, $data));
    }

    /**
     * L'utilisateur accepte-t-il les notifications système ?
     *
     * ⚠️ **Une préférence ABSENTE vaut accord.** `notification_preferences` vaut `{}`
     * pour tous les comptes existants — personne n'a jamais ouvert l'écran de réglages —
     * et traiter l'absence comme un refus couperait les notifications de toute la flotte
     * d'un seul déploiement. Seul un `false` explicite fait taire les push.
     *
     * La préférence porte sur le PUSH seul : la trace en base est écrite dans tous les
     * cas, pour que refuser les alertes système ne revienne pas à se priver de
     * l'information dans l'application.
     */
    private function acceptePush(User $user): bool
    {
        $preferences = $user->notification_preferences ?? [];

        return ($preferences['push_notifications'] ?? true) !== false;
    }

    /** @param  array<string, mixed>  $data */
    private function pousser(User $user, string $title, string $body, array $data): void
    {
        try {
            $messaging = app(Messaging::class);
        } catch (\Throwable $e) {
            // Firebase mal configuré ou injoignable : la trace en base est déjà posée,
            // et faire échouer la réservation qui a déclenché l'envoi serait absurde.
            Log::warning('Push indisponible', ['user_id' => $user->id, 'erreur' => $e->getMessage()]);

            return;
        }

        foreach ($user->fcmTokens as $fcmToken) {
            try {
                $messaging->send(
                    CloudMessage::withTarget('token', $fcmToken->token)
                        ->withNotification(FcmNotification::create($title, $body))
                        // FCM n'accepte que des chaînes dans `data` : un entier ou un
                        // booléen y fait échouer l'envoi entier, sans message utile.
                        ->withData(array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $data))
                );
            } catch (\Throwable $e) {
                // Jeton invalide ou expiré — l'appareil a été réinitialisé, ou
                // l'application désinstallée. On le retire plutôt que de réessayer
                // indéfiniment à chaque envoi.
                $fcmToken->delete();
            }
        }
    }
}

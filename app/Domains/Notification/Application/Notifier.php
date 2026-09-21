<?php

namespace App\Domains\Notification\Application;

use App\Models\Booking;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehiclePause;
use App\Services\FcmNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Qui est prévenu de quoi — la table de routage des notifications.
 *
 * TOUT passe par ici. C'est délibéré : éparpiller des appels d'envoi dans les actions
 * rendrait impossible de répondre à « qui reçoit quoi ? » autrement qu'en relisant le
 * dépôt entier, et personne ne le ferait. Une méthode par événement, le destinataire
 * écrit noir sur blanc.
 *
 * ## La règle, telle qu'elle a été arrêtée le 2026-09-21
 *
 * | Événement                        | Destinataire                       |
 * |----------------------------------|------------------------------------|
 * | Nouvelle réservation             | tous les agents                    |
 * | Course acceptée / démarrée /     | les administrateurs                |
 * | terminée / annulée / révoquée    |                                    |
 * | Demande de pause déposée         | les administrateurs                |
 * | Pause validée / refusée          | l'agent concerné                   |
 * | Véhicule mis en pause / reprise  | le propriétaire du véhicule        |
 * | Paiement enregistré              | la personne payée, elle seule      |
 *
 * ⚠️ « Les administrateurs sont prévenus de toutes les actions agent » ne veut PAS dire
 * qu'ils reçoivent tout : une pause validée ne leur revient pas, puisque c'est l'un
 * d'eux qui vient de la valider. Un accusé de réception de sa propre action est du bruit,
 * et le bruit finit par faire couper les notifications.
 *
 * ⚠️ Un paiement ne va PAS aux administrateurs : ce n'est pas une action d'agent, et
 * c'est l'événement le plus fréquent de l'application — les paiements journaliers sont
 * générés du lundi au vendredi pour chaque contrat. Les router vers tous les
 * administrateurs noierait tout le reste.
 *
 * ## Les destinations
 *
 * ⚠️ Les notifications destinées aux ADMINISTRATEURS ne portent pas d'URL, et ce n'est
 * pas un oubli : l'espace admin du front Nuxt n'est pas encore migré, et une destination
 * qui mène à un écran inexistant est pire qu'aucune destination. À rétablir quand cet
 * espace existera — les chemins sont indiqués en commentaire à chaque appel.
 */
final class Notifier
{
    public function __construct(private readonly FcmNotificationService $push) {}

    // ----- Réservations ------------------------------------------------------

    /** Une réservation vient d'être créée : tous les agents peuvent la prendre. */
    public function bookingCreated(Booking $booking): void
    {
        $this->versLesAgents(
            'Nouvelle réservation disponible',
            "Trajet : {$booking->from_location} → {$booking->to_location}",
            'info',
            '/driver/bookings/available',
        );
    }

    /** Un agent a pris une course. */
    public function bookingAccepted(Booking $booking, User $agent): void
    {
        // Destination future : /admin/bookings/{$booking->id}
        $this->versLesAdmins(
            'Course acceptée',
            $this->nomDe($agent).' a accepté la course '.$booking->booking_number,
            'success',
        );
    }

    public function bookingStarted(Booking $booking, User $agent): void
    {
        $this->versLesAdmins(
            'Course démarrée',
            $this->nomDe($agent).' a démarré la course '.$booking->booking_number,
            'info',
        );
    }

    public function bookingCompleted(Booking $booking, User $agent): void
    {
        $this->versLesAdmins(
            'Course terminée',
            $this->nomDe($agent).' a terminé la course '.$booking->booking_number,
            'success',
        );
    }

    public function bookingCancelled(Booking $booking, User $agent, ?string $motif = null): void
    {
        $this->versLesAdmins(
            'Course annulée par un agent',
            $this->nomDe($agent).' a annulé la course '.$booking->booking_number
                .($motif ? " — motif : {$motif}" : ''),
            // `warning` et non `error` : une annulation est un fait de gestion prévu,
            // pas une panne. Réserver le rouge à ce qui exige une intervention.
            'warning',
        );
    }

    public function subscriptionRevoked(Booking $booking, User $agent): void
    {
        $this->versLesAdmins(
            'Course d\'abonnement révoquée',
            $this->nomDe($agent).' a révoqué la course '.$booking->booking_number
                .' : elle redevient disponible pour tous.',
            'warning',
        );
    }

    // ----- Pauses agent ------------------------------------------------------

    /** Un agent dépose une demande : les administrateurs ont à la traiter. */
    public function leaveRequested(LeaveRequest $demande): void
    {
        $agent = $demande->driver?->user;

        // Destination future : /admin/leaves/requests
        $this->versLesAdmins(
            'Nouvelle demande de pause',
            $this->nomDe($agent).' demande '.$demande->requested_days.' jour(s) à partir du '
                .$this->jour($demande->start_date),
            'info',
        );
    }

    /**
     * Une demande est validée : l'AGENT est prévenu, pas les administrateurs.
     *
     * ⚠️ C'est un administrateur qui vient de valider : lui renvoyer l'information, ainsi
     * qu'à ses collègues, serait un accusé de réception de sa propre action.
     */
    public function leaveApproved(LeaveRequest $demande): void
    {
        $this->vers(
            $demande->driver?->user,
            'Votre pause est validée',
            'Elle commence le '.$this->jour($demande->start_date)
                .' — '.$demande->requested_days.' jour(s).',
            'success',
            '/driver/leaves',
        );
    }

    public function leaveRejected(LeaveRequest $demande): void
    {
        $this->vers(
            $demande->driver?->user,
            'Votre demande de pause est refusée',
            $demande->rejection_reason
                ? 'Motif : '.$demande->rejection_reason
                : 'Contactez un administrateur pour en connaître la raison.',
            'error',
            '/driver/leaves',
        );
    }

    // ----- Pauses véhicule ---------------------------------------------------

    /**
     * Un véhicule est mis en pause : son PROPRIÉTAIRE est prévenu, avec le motif.
     *
     * Le motif est le cœur du message : un propriétaire qui voit son véhicule à l'arrêt
     * sans savoir pourquoi appelle l'administration, et c'est précisément cet appel que
     * la notification remplace.
     */
    public function vehiclePaused(VehiclePause $pause): void
    {
        $vehicule = $pause->vehicle;

        $this->vers(
            $vehicule?->owner,
            'Véhicule en pause',
            $this->immatriculation($vehicule).' est en pause depuis le '.$this->jour($pause->start_date)
                .' — motif : '.$pause->reason_label
                .($pause->reason_notes ? " ({$pause->reason_notes})" : ''),
            'warning',
            $vehicule ? "/owner/vehicles/{$vehicule->id}/pauses" : null,
        );
    }

    public function vehiclePauseEnded(VehiclePause $pause): void
    {
        $vehicule = $pause->vehicle;

        $this->vers(
            $vehicule?->owner,
            'Reprise du véhicule',
            $this->immatriculation($vehicule).' a repris du service le '.$this->jour($pause->end_date).'.',
            'success',
            $vehicule ? "/owner/vehicles/{$vehicule->id}/pauses" : null,
        );
    }

    // ----- Paiements ---------------------------------------------------------

    /**
     * Un paiement est enregistré : la personne payée, et elle seule.
     *
     * ⚠️ Pas les administrateurs. C'est l'événement le plus fréquent de l'application —
     * les paiements journaliers sont générés du lundi au vendredi pour chaque contrat —
     * et les router vers tous les administrateurs noierait tout le reste.
     *
     * La destination dépend du destinataire : l'espace propriétaire a un écran de
     * paiements par véhicule, l'espace agent n'en a pas encore.
     */
    public function paymentRecorded(Payment $paiement): void
    {
        $agent = $paiement->driver?->user;

        $this->vers(
            $agent,
            'Paiement enregistré',
            $this->montant($paiement->net_amount ?? $paiement->amount)
                .' — '.$this->libellePaiement($paiement->payment_type),
            'success',
            // Pas de destination : l'espace agent n'a pas encore d'écran de paiements.
            null,
        );
    }

    // ----- Acheminement ------------------------------------------------------

    private function vers(?User $destinataire, string $titre, string $message, string $ton, ?string $url): void
    {
        // Un destinataire absent — agent dont le compte a été supprimé, véhicule sans
        // propriétaire — ne doit pas faire échouer l'action métier.
        if (! $destinataire) {
            return;
        }

        $this->sansCasser(fn () => $this->push->sendToUser($destinataire, $titre, $message, $this->charge($ton, $url)));
    }

    private function versLesAdmins(string $titre, string $message, string $ton, ?string $url = null): void
    {
        $this->sansCasser(function () use ($titre, $message, $ton, $url) {
            $this->administrateurs()->each(
                fn (User $admin) => $this->push->sendToUser($admin, $titre, $message, $this->charge($ton, $url))
            );
        });
    }

    private function versLesAgents(string $titre, string $message, string $ton, ?string $url = null): void
    {
        // Délégué au service, qui sait charger les jetons de tous les agents en une requête.
        $this->sansCasser(fn () => $this->push->sendToDrivers($titre, $message, $this->charge($ton, $url)));
    }

    /**
     * Exécute un envoi sans jamais laisser remonter d'exception.
     *
     * ⚠️ Une notification est un EFFET DE BORD. Elle ne doit jamais empêcher d'accepter
     * une course, de valider une pause ou d'enregistrer un paiement. Le risque est
     * concret : ces actions tournent dans des transactions, et une exception ici
     * annulerait l'opération métier tout entière.
     *
     * L'échec part au journal, où il reste trouvable — le silence complet cacherait une
     * chaîne de notification cassée, ce qui est précisément arrivé pendant des mois avec
     * `FcmToken`.
     */
    private function sansCasser(callable $envoi): void
    {
        try {
            $envoi();
        } catch (\Throwable $e) {
            Log::error('Notification non envoyée : '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /** @return Collection<int, User> */
    private function administrateurs(): Collection
    {
        // ⚠️ Sur le PROFIL, et non sur le rôle. Les noms de rôles diffèrent entre local
        // et production, et `lecteur` — libellé « Utilisateur » en ligne — porte 27
        // permissions d'écriture : c'est bien un administrateur au sens de cette table.
        return User::where('profil', 'admin')->with('fcmTokens')->get();
    }

    /** @return array<string, mixed> */
    private function charge(string $ton, ?string $url): array
    {
        return array_filter([
            'type' => $ton,
            'url' => $url,
        ], fn ($v) => $v !== null);
    }

    // ----- Mise en forme -----------------------------------------------------

    private function nomDe(?User $user): string
    {
        return $user?->name ?: 'Un agent';
    }

    /** Le numéro du véhicule — colonne `vehicle_number`, il n'y a pas de plaque en base. */
    private function immatriculation(?object $vehicule): string
    {
        return $vehicule?->vehicle_number ? 'Le véhicule '.$vehicule->vehicle_number : 'Votre véhicule';
    }

    private function jour(mixed $date): string
    {
        if (! $date) {
            return 'une date non précisée';
        }

        return Carbon::parse($date)->format('d/m/Y');
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 0, ',', ' ').' FCFA';
    }

    private function libellePaiement(?string $type): string
    {
        return match ($type) {
            'commission' => 'commission',
            'contract' => 'versement de contrat',
            'bonus' => 'prime',
            default => 'paiement',
        };
    }
}

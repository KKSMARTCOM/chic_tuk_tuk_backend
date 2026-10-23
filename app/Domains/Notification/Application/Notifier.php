<?php

namespace App\Domains\Notification\Application;

use App\Models\Announcement;
use App\Models\Booking;
use App\Models\LeaveRequest;
use App\Models\OwnerDisbursement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\SubscriptionEnrollment;
use App\Models\User;
use App\Models\VehicleContract;
use App\Models\Vehicle;
use App\Models\VehicleContractPreparationStep;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
 * | Abonnement transféré             | le nouvel agent titulaire          |
 * | Demande de pause déposée         | les administrateurs                |
 * | Pause validée / refusée          | l'agent concerné                   |
 * | Véhicule mis en pause / reprise  | le propriétaire du véhicule        |
 * | Paiement validé / annulé          | l'agent concerné, lui seul         |
 * | Communiqué envoyé / relancé       | ses destinataires (arrêtés à l'envoi) |
 *
 * ⚠️ « Les administrateurs sont prévenus de toutes les actions agent » ne veut PAS dire
 * qu'ils reçoivent tout : une pause validée ne leur revient pas, puisque c'est l'un
 * d'eux qui vient de la valider. Un accusé de réception de sa propre action est du bruit,
 * et le bruit finit par faire couper les notifications.
 *
 * ⚠️ C'est l'ACTION sur un paiement — sa validation, son annulation — qui est notifiée,
 * jamais sa création. Les paiements journaliers sont générés chaque soir du lundi au
 * vendredi pour chaque contrat : notifier la création enverrait à chaque agent une alerte
 * quotidienne perpétuelle pour une écriture sur laquelle il n'a rien à faire. Et pas aux
 * administrateurs : ce n'est pas une action d'agent, et c'est l'un d'eux qui agit.
 *
 * ## Les destinations
 *
 * Les notifications destinées aux ADMINISTRATEURS n'en portaient aucune tant que leur
 * espace n'existait pas dans le front Nuxt : une destination qui mène à un écran
 * inexistant est pire qu'aucune destination. Les écrans de RÉSERVATIONS et de PAUSES
 * ayant été livrés le 2026-09-22, leurs notifications pointent désormais dessus.
 *
 * ⚠️ N'ajouter une destination qu'une fois l'écran EN LIGNE, pas une fois écrit : le
 * backend se déploie avant le front, et une notification partie entre les deux mènerait
 * à un 404 que personne ne saurait expliquer.
 */
final class Notifier
{
    public function __construct(private readonly PushSender $push) {}

    // ----- Réservations ------------------------------------------------------

    /**
     * Une réservation vient d'être créée.
     *
     * Deux publics, deux messages — et c'est voulu. Les AGENTS sont invités à la prendre
     * et repartent vers les courses disponibles ; les ADMINISTRATEURS sont informés
     * qu'elle est arrivée, et repartent vers son dossier. Un seul envoi commun aurait
     * renvoyé les administrateurs vers un écran d'agent que leur jeton refuse.
     *
     * ⚠️ Les administrateurs ont été ajoutés le 2026-09-22 : ils ne voyaient une nouvelle
     * réservation qu'en ouvrant l'application, alors que ce sont eux qui affectent les
     * courses qu'aucun agent ne prend.
     */
    public function bookingCreated(Booking $booking): void
    {
        $trajet = "Trajet : {$booking->from_location} → {$booking->to_location}";

        $this->versLesAgents(
            'Nouvelle réservation disponible',
            $trajet,
            'info',
            '/driver/bookings/available',
        );

        $this->versLesAdmins(
            'Nouvelle réservation',
            $booking->booking_number.' — '.$trajet,
            'info',
            "/admin/bookings/{$booking->id}",
        );
    }

    /**
     * La clôture d'une course a été annulée par l'administration.
     *
     * ⚠️ Prévient l'AGENT, et lui seul. Son gain lui a été retiré et sa commission
     * effacée : c'est sur son compte que cela change quelque chose. Les administrateurs
     * n'en sont pas informés pour la même raison qu'une pause validée ne leur revient
     * pas — c'est l'un d'eux qui vient d'agir.
     */
    public function bookingReopened(Booking $booking): void
    {
        $this->vers(
            $booking->driver?->user,
            'Course rouverte',
            'La course '.$booking->booking_number.' a été rouverte : son gain et sa commission ont été annulés.',
            'warning',
            '/driver/bookings/assigned',
        );
    }

    /** Un agent a pris une course. */
    public function bookingAccepted(Booking $booking, User $agent): void
    {
        $this->versLesAdmins(
            'Course acceptée',
            $this->nomDe($agent).' a accepté la course '.$booking->booking_number,
            'success',
            "/admin/bookings/{$booking->id}",
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

    /**
     * Un administrateur a transféré un abonnement à un autre agent : le NOUVEAU titulaire
     * est prévenu, puisque des courses lui arrivent sans qu'il les ait demandées. Pas les
     * administrateurs : c'est l'un d'eux qui agit.
     */
    public function subscriptionTransferred(Booking $parent, User $newAgent): void
    {
        $this->vers(
            $newAgent,
            'Abonnement transféré',
            "Vous êtes désormais titulaire de l'abonnement {$parent->booking_number} : "
                ."{$parent->from_location} → {$parent->to_location}",
            'info',
            '/driver/bookings/available',
        );
    }

    // ----- Pauses agent ------------------------------------------------------

    /** Un agent dépose une demande : les administrateurs ont à la traiter. */
    public function leaveRequested(LeaveRequest $demande): void
    {
        $agent = $demande->driver?->user;

        // La file des demandes n'est plus au menu depuis le 2026-09-22, mais sa route
        // existe toujours : c'est bien là qu'on tranche une demande.
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

    /**
     * Le départ d'un agent était une erreur, l'administrateur l'a annulé (2026-10-21) : le
     * véhicule, que le propriétaire voyait immobilisé, est de nouveau en service.
     */
    public function vehicleBackAfterReopen(Vehicle $vehicle): void
    {
        $this->vers(
            $vehicle->owner,
            'Reprise du véhicule',
            $this->immatriculation($vehicle).' est de nouveau en service : le départ de son agent a été annulé.',
            'success',
            "/owner/vehicles/{$vehicle->id}",
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
     * Un paiement est validé : l'agent concerné, et lui seul.
     *
     * ⚠️ C'est l'ACTION sur le paiement qui est notifiée, et non sa création. La création
     * est majoritairement automatique — `generateDailyPaymentForContract()` tourne chaque
     * soir du lundi au vendredi pour chaque contrat actif — et la notifier enverrait à
     * chaque agent une alerte quotidienne perpétuelle pour une écriture comptable sur
     * laquelle il n'a rien à faire. La validation, elle, est un geste d'administrateur
     * qui change quelque chose pour l'agent : son argent est reconnu comme dû.
     *
     * ⚠️ Pas aux administrateurs : ce n'est pas une action d'agent, et c'est l'un d'eux
     * qui vient de valider.
     */
    public function paymentValidated(Payment $paiement): void
    {
        $this->vers(
            $paiement->driver?->user,
            'Paiement validé',
            // Le BRUT : l'agent ne connaît que ce montant ; le net est l'affaire du
            // propriétaire et des administrateurs (2026-10-08).
            'Votre paiement du '.$this->jour($paiement->payment_date).' a été validé — '
                .$this->montant($paiement->amount)
                .' ('.$this->libellePaiement($paiement->payment_type).').',
            'success',
            // Pas de destination : l'espace agent n'a pas encore d'écran de paiements.
            null,
        );
    }

    /**
     * Un paiement est annulé.
     *
     * Le ton est `warning` et non `error` : une annulation est un fait de gestion, pas
     * une panne. Mais elle doit être dite — un agent qui comptait sur cette somme a le
     * droit de l'apprendre autrement qu'en s'en apercevant.
     */
    public function paymentCancelled(Payment $paiement): void
    {
        $this->vers(
            $paiement->driver?->user,
            'Paiement annulé',
            'Votre paiement du '.$this->jour($paiement->payment_date).' a été annulé — '
                .$this->montant($paiement->amount)
                .'. Contactez un administrateur si cela vous semble erroné.',
            'warning',
            null,
        );
    }

    /**
     * Un versement fait au propriétaire (spec 2026-10-22-versement-proprietaire, §4.4). Trois
     * conditions, sans quoi il ne part pas : daté au plus tôt du jour de la livraison (le
     * réglage absent, rien ne part — la régularisation de l'historique reste silencieuse),
     * fiches visibles aux propriétaires, et le propriétaire du contrat l'est encore du
     * véhicule — sinon le lien mènerait à un 404.
     */
    public function disbursementRecorded(OwnerDisbursement $disbursement): void
    {
        // Un effet de bord : rien ici ne doit faire échouer un versement déjà enregistré.
        $this->sansCasser(function () use ($disbursement) {
            $from = $this->notifyFrom();
            $contract = $disbursement->contract()->with('vehicle.owner')->first();
            $vehicle = $contract?->vehicle;
            if (! $from || $disbursement->paid_on->lt($from)
                || ! RemunerationStatement::visibleToOwners()
                || ! $vehicle || $vehicle->owner_id !== $contract->owner_id) {
                return;
            }

            $this->vers(
                $vehicle->owner,
                'Versement de votre revenu',
                $vehicle->vehicle_number.' : '.number_format((float) $disbursement->amount, 0, ',', ' ').' F versés le '.$disbursement->paid_on->format('d/m/Y').'.',
                'success',
                "/owner/vehicles/{$vehicle->id}/payments?view=disbursements",
            );
            $disbursement->forceFill(['owner_notified_at' => now()])->save();
        });
    }

    /** Le jour de la livraison, lu comme une date ; illisible ou absent : aucun versement ne notifie. */
    private function notifyFrom(): ?Carbon
    {
        $value = trim((string) config('remuneration.disbursement_notify_from'));
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            Log::warning("REMUNERATION_DISBURSEMENT_NOTIFY_FROM illisible : « {$value} » ; aucun versement ne notifie.");

            return null;
        }
    }

    /** Une étape de préparation cochée : le propriétaire suit son véhicule (spec 2026-10-20, §4.4). */
    public function preparationStepDone(VehicleContractPreparationStep $step): void
    {
        $vehicle = $step->vehicleContract?->vehicle;
        // « Installation… » devient « installation… », mais un sigle garde sa capitale : « GPS… » (M-8).
        $second = mb_substr($step->label, 1, 1);
        $label = $second !== '' && $second === mb_strtoupper($second) && $second !== mb_strtolower($second)
            ? $step->label
            : mb_strtolower(mb_substr($step->label, 0, 1)).mb_substr($step->label, 1);

        $this->vers(
            $vehicle?->owner,
            'Préparation de votre véhicule',
            ($vehicle?->vehicle_number ?? '').' : '.$label.', faite le '.$step->done_on?->format('d/m/Y').'.',
            'info',
            $vehicle ? "/owner/vehicles/{$vehicle->id}" : null,
        );
    }

    /** Le contrat en attente a commencé : le véhicule roule pour son propriétaire (2026-10-09). */
    public function vehicleInService(VehicleContract $contract): void
    {
        $vehicle = $contract->vehicle;

        $this->vers(
            $vehicle?->owner,
            'Véhicule en service',
            'Votre véhicule '.($vehicle?->vehicle_number ?? '').' est en service depuis le '.$contract->start_date->format('d/m/Y').'.',
            'success',
            $vehicle ? "/owner/vehicles/{$vehicle->id}" : null,
        );
    }

    /**
     * Un cumul du contrat atteint à la validation d'une fiche (spec 2026-10-12, §4.1).
     *
     * @param  'revenue'|'charges'  $target
     */
    public function contractTargetReached(VehicleContract $contract, string $target, CarbonInterface $month): void
    {
        $plate = $contract->vehicle?->vehicle_number ?? '';
        $what = $target === 'revenue' ? 'le revenu total du contrat est atteint' : 'le total des charges du contrat est atteint';

        $this->versLesAdmins('Cumul du contrat atteint', "{$plate} : {$what} (fiche de ".$month->translatedFormat('F Y').').', 'info', "/admin/vehicle-contracts/{$contract->id}");
    }

    /** Les deux cumuls atteints : le contrat peut être soldé (spec 2026-10-12, §4.1). */
    public function contractReadyToSettle(VehicleContract $contract): void
    {
        $plate = $contract->vehicle?->vehicle_number ?? '';

        $this->versLesAdmins('Contrat à solder', "{$plate} : les deux cumuls sont atteints : vous pouvez solder le contrat.", 'warning', "/admin/vehicle-contracts/{$contract->id}");
    }

    /** Le contrat soldé par l'admin (spec 2026-10-12, §4.2). */
    public function vehicleContractSettled(VehicleContract $contract): void
    {
        $vehicle = $contract->vehicle;

        $this->vers(
            $vehicle?->owner,
            'Contrat terminé',
            'Le contrat de votre véhicule '.($vehicle?->vehicle_number ?? '').' a pris fin le '.$contract->end_date->format('d/m/Y').'.',
            'success',
            $vehicle ? "/owner/vehicles/{$vehicle->id}" : null,
        );
    }

    // ----- Fiches de rémunération -------------------------------------------

    public function remunerationStatementIssued(RemunerationStatement $statement): void
    {
        $vehicle = $statement->contract?->vehicle;

        $this->vers(
            $vehicle?->owner,
            'Fiche de rémunération '.$this->monthWithArticle($statement->month),
            // `immatriculation()` ouvre une phrase (« Le véhicule … ») : ici le véhicule vient
            // en milieu de phrase.
            'Votre fiche de rémunération '.$statement->number.' pour '
                .($vehicle?->vehicle_number ? 'le véhicule '.$vehicle->vehicle_number : 'votre véhicule')
                .' est disponible : solde dû '.$this->montant($statement->balance_due).'.',
            'success',
            $vehicle ? "/owner/vehicles/{$vehicle->id}/payments" : null,
        );
    }

    public function remunerationStatementCancelled(RemunerationStatement $statement): void
    {
        $vehicle = $statement->contract?->vehicle;

        $this->vers(
            $vehicle?->owner,
            'Fiche de rémunération annulée',
            'Votre fiche de rémunération '.$this->monthWithArticle($statement->month)
                .' a été annulée, une version corrigée va suivre.',
            'warning',
            $vehicle ? "/owner/vehicles/{$vehicle->id}/payments" : null,
        );
    }

    /** « d'octobre 2026 », « d'août 2026 », mais « de juillet 2026 ». */
    private function monthWithArticle(CarbonInterface $month): string
    {
        $label = $month->locale('fr')->translatedFormat('F Y');

        return (in_array(mb_substr($label, 0, 1), ['a', 'e', 'i', 'o', 'u', 'é'], true) ? 'd\'' : 'de ').$label;
    }

    // ----- Communiqués -------------------------------------------------------

    /**
     * Un communiqué arrive chez l'un de ses destinataires (spec 2026-10-13, §5.2).
     *
     * ⚠️ Un appel PAR destinataire, depuis le job `DeliverAnnouncement` : la liste a été
     * arrêtée à l'envoi, et ce n'est pas au Notifier de la recalculer. `announcement_id`
     * dans la charge permet de marquer la cloche lue quand le communiqué est ouvert.
     */
    public function announcementPublished(Announcement $announcement, User $user): void
    {
        $this->versCommunique($user, 'Nouveau communiqué', $announcement);
    }

    /** La relance d'un communiqué non lu — seulement aux non-lus, choisis par le job. */
    public function announcementReminder(Announcement $announcement, User $user): void
    {
        $this->versCommunique($user, 'Rappel : communiqué non lu', $announcement);
    }

    private function versCommunique(User $user, string $titre, Announcement $announcement): void
    {
        $this->sansCasser(fn () => $this->push->sendToUser($user, $titre, $announcement->title, [
            'type' => 'info',
            'url' => "/announcements/{$announcement->id}",
            'announcement_id' => $announcement->id,
        ]));
    }

    /** Une inscription d'abonnement est arrivée (2026-10-14) : les administrateurs. */
    public function enrollmentSubmitted(SubscriptionEnrollment $enrollment): void
    {
        $booking = $enrollment->booking;
        $this->versLesAdmins(
            'Inscription reçue',
            "{$enrollment->client_name} a rempli l'inscription de l'abonnement {$booking?->booking_number}.",
            'info',
            $booking ? "/admin/bookings/{$booking->id}" : null,
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

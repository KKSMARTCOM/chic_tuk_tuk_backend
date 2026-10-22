<?php

namespace App\Domains\Audit\Application;

use App\Domains\Audit\Domain\ActivityEvent;
use App\Domains\Booking\Domain\Enums\BookingStatus;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Workforce\Domain\Enums\DriverContractEndReason;
use App\Models\Announcement;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\InternalAssignment;
use App\Models\LeaveRequest;
use App\Models\OwnerDisbursement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\Role;
use App\Models\SubscriptionEnrollment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Qui a fait quoi — le journal d'activité (2026-09-29).
 *
 * TOUT passe par ici, sur le modèle du `Notifier` : une méthode par événement, la phrase
 * écrite noir sur blanc. Répondre à « qu'est-ce qui est tracé ? » se fait en lisant ce
 * fichier, pas le dépôt entier.
 *
 * ## Où l'appeler
 *
 * ⚠️ Depuis les CONTRÔLEURS pour un geste fait par quelqu'un, pas depuis les actions.
 * C'est le contrôleur qui connaît l'intention : l'affectation d'un agent par
 * l'administrateur passe par `AcceptBooking`, exactement comme une course prise par
 * l'agent — tracer dans l'action écrirait « Koffi a accepté » quand Awa a affecté. Les
 * tâches planifiées, sans contrôleur, tracent depuis leur action, au nom du « Système ».
 *
 * Toujours APRÈS l'action réussie, jamais dedans : une ligne écrite dans une transaction
 * annulée raconterait ce qui n'a pas eu lieu.
 *
 * ## Ce qui est stocké
 *
 * `description` est la phrase SANS son auteur (« a affecté Koffi à la course
 * CTT-4F2A ») ; l'auteur est dans `properties.actor`, figé au moment de l'action — un
 * compte renommé ou supprimé ne réécrit pas l'histoire. Le front affiche « {actor}
 * {description} ». `properties.ip` quand l'action vient d'une requête.
 *
 * ⚠️ Le journal n'échoue jamais bruyamment : une trace est un effet de bord, elle ne doit
 * pas pouvoir faire échouer une affectation ou un paiement. Les échecs vont au journal
 * applicatif.
 */
final class ActivityJournal
{
    public const SYSTEM = 'Système';

    // ----- Connexions et comptes ---------------------------------------------------------

    public function loggedIn(User $user): void
    {
        $this->record(ActivityEvent::Login, $user, 'a ouvert une session', causer: $user);
    }

    /**
     * Une connexion refusée. `$user` est le compte visé quand il est connu — il ne l'est
     * pas pour une adresse inconnue, et l'adresse saisie est alors la seule trace.
     *
     * @param  'bad_credentials'|'locked'|'disabled'  $reason
     */
    public function loginFailed(string $email, string $reason, ?User $user = null): void
    {
        $why = match ($reason) {
            'locked' => 'compte verrouillé',
            'disabled' => 'compte désactivé',
            default => 'identifiants incorrects',
        };

        $this->record(
            ActivityEvent::LoginFailed,
            $user,
            "Connexion refusée pour {$email} ({$why})",
            ['email' => $email, 'reason' => $reason],
            actor: '',
        );
    }

    public function loggedOut(User $user): void
    {
        $this->record(ActivityEvent::Logout, $user, 'a fermé sa session', causer: $user);
    }

    public function sessionRevoked(User $user, ?string $userAgent): void
    {
        $this->record(
            ActivityEvent::SessionRevoked,
            $user,
            'a déconnecté un de ses appareils',
            ['user_agent' => $userAgent],
            causer: $user,
        );
    }

    public function otherSessionsRevoked(User $user): void
    {
        $this->record(ActivityEvent::OtherSessionsRevoked, $user, 'a déconnecté ses autres appareils', causer: $user);
    }

    public function passwordChanged(User $user): void
    {
        $this->record(ActivityEvent::PasswordChanged, $user, 'a changé son mot de passe', causer: $user);
    }

    public function passwordReset(User $user): void
    {
        $this->record(ActivityEvent::PasswordReset, $user, 'a réinitialisé son mot de passe par le lien reçu', causer: $user);
    }

    // ----- Réservations ------------------------------------------------------------------

    /** Une réservation du tunnel public : son auteur est le client, sans compte. */
    public function bookingCreatedOnline(Booking $booking): void
    {
        $this->record(
            ActivityEvent::BookingCreated,
            $booking,
            "a réservé en ligne la course {$booking->booking_number}",
            actor: trim(($booking->client_name ?: 'Un client').' (en ligne)'),
        );
    }

    public function enrollmentSubmitted(SubscriptionEnrollment $enrollment): void
    {
        $this->record(ActivityEvent::BookingEnrollmentSubmitted, $enrollment->booking,
            "a rempli l'inscription de l'abonnement {$enrollment->booking?->booking_number}",
            actor: trim(($enrollment->client_name ?: 'Un client').' (en ligne)'));
    }

    /** @param  list<string>  $fields */
    public function enrollmentCorrected(SubscriptionEnrollment $enrollment, array $fields): void
    {
        $this->record(ActivityEvent::BookingEnrollmentCorrected, $enrollment->booking,
            "a corrigé l'inscription de l'abonnement {$enrollment->booking?->booking_number}",
            ['fields' => $fields]);
    }

    public function enrollmentLinkRenewed(SubscriptionEnrollment $enrollment): void
    {
        $this->record(ActivityEvent::BookingEnrollmentLinkRenewed, $enrollment->booking,
            "a renouvelé le lien d'inscription de l'abonnement {$enrollment->booking?->booking_number}");
    }

    public function enrollmentIdDocumentsPurged(int $count): void
    {
        $this->record(ActivityEvent::BookingEnrollmentIdDocumentPurged, null, "a effacé {$count} pièce(s) d'identité d'abonnements terminés");
    }

    public function bookingCreated(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingCreated, $booking, "a créé la réservation {$booking->booking_number}");
    }

    public function bookingUpdated(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingUpdated, $booking, "a modifié la réservation {$booking->booking_number}");
    }

    /** Le numéro est passé à part : la réservation n'existe plus quand on l'écrit. */
    public function bookingDeleted(string $bookingId, string $bookingNumber): void
    {
        $this->record(
            ActivityEvent::BookingDeleted,
            null,
            "a supprimé la réservation {$bookingNumber}",
            ['booking_id' => $bookingId, 'booking_number' => $bookingNumber],
        );
    }

    public function driverAssigned(Booking $booking, ?Driver $driver): void
    {
        $this->record(
            ActivityEvent::BookingDriverAssigned,
            $booking,
            "a affecté {$this->driverName($driver)} à la course {$booking->booking_number}",
            ['driver_id' => $driver?->id],
        );
    }

    public function driverRemoved(Booking $booking, ?Driver $driver): void
    {
        $this->record(
            ActivityEvent::BookingDriverRemoved,
            $booking,
            "a retiré {$this->driverName($driver)} de la course {$booking->booking_number}",
            ['driver_id' => $driver?->id],
        );
    }

    public function bookingStatusChanged(Booking $booking, string $from, string $to): void
    {
        $this->record(
            ActivityEvent::BookingStatusChanged,
            $booking,
            "a passé la course {$booking->booking_number} de « {$this->statusLabel($from)} » à « {$this->statusLabel($to)} »",
            ['from' => $from, 'to' => $to],
        );
    }

    public function bookingReopened(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingReopened, $booking, "a rouvert la course terminée {$booking->booking_number}");
    }

    public function bookingAccepted(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingAccepted, $booking, "a accepté la course {$booking->booking_number}");
    }

    public function bookingStarted(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingStarted, $booking, "a démarré la course {$booking->booking_number}");
    }

    public function bookingCompleted(Booking $booking): void
    {
        $this->record(ActivityEvent::BookingCompleted, $booking, "a terminé la course {$booking->booking_number}");
    }

    public function bookingCancelled(Booking $booking, ?string $reason): void
    {
        $this->record(
            ActivityEvent::BookingCancelled,
            $booking,
            "a annulé la course {$booking->booking_number}".($reason ? " : « {$reason} »" : ''),
            ['reason' => $reason],
        );
    }

    /** Tâche planifiée : une réservation restée en attente 24 h après son heure. */
    public function bookingExpired(Booking $booking): void
    {
        $this->record(
            ActivityEvent::BookingExpired,
            $booking,
            "a expiré la réservation {$booking->booking_number}, restée sans agent",
            actor: self::SYSTEM,
        );
    }

    public function subscriptionRevoked(Booking $booking): void
    {
        $this->record(ActivityEvent::SubscriptionRevoked, $booking, "a rendu la course d'abonnement {$booking->booking_number}");
    }

    public function subscriptionTransferred(Booking $booking, ?Driver $to): void
    {
        $this->record(
            ActivityEvent::SubscriptionTransferred,
            $booking,
            "a transféré l'abonnement {$booking->booking_number} à {$this->driverName($to)}",
            ['driver_id' => $to?->id],
        );
    }

    public function subscriptionTerminated(Booking $booking, ?string $reason): void
    {
        $this->record(
            ActivityEvent::SubscriptionTerminated,
            $booking,
            "a résilié l'abonnement {$booking->booking_number}".($reason ? " : « {$reason} »" : ''),
            ['reason' => $reason],
        );
    }

    // ----- Comptes -----------------------------------------------------------------------
    //
    // Communs aux propriétaires, agents et administrateurs : « le compte propriétaire de
    // Rachid Bello ». Le libellé du profil vient de `Profil::label()`.

    /**
     * `$subject` : l'objet dont la fiche décrit ce compte, quand ce n'est pas le compte
     * lui-même — un AGENT, dont la fiche est adressée par l'agent et non par son compte.
     */
    public function accountCreated(User $account, ?Model $subject = null): void
    {
        $this->record(ActivityEvent::AccountCreated, $subject ?? $account, "a créé le compte {$this->accountLabel($account)}", ['profil' => $account->profil]);
    }

    public function accountUpdated(User $account, ?Model $subject = null): void
    {
        $this->record(ActivityEvent::AccountUpdated, $subject ?? $account, "a modifié le compte {$this->accountLabel($account)}", ['profil' => $account->profil]);
    }

    public function accountStatusChanged(User $account, bool $active, ?Model $subject = null): void
    {
        $verb = $active ? 'a réactivé' : 'a désactivé';

        $this->record(
            ActivityEvent::AccountStatusChanged,
            $subject ?? $account,
            "{$verb} le compte {$this->accountLabel($account)}",
            ['active' => $active, 'profil' => $account->profil],
        );
    }

    public function accountPasswordSet(User $account, ?Model $subject = null): void
    {
        $this->record(
            ActivityEvent::AccountPasswordSet,
            $subject ?? $account,
            "a défini un nouveau mot de passe pour le compte {$this->accountLabel($account)}",
            ['profil' => $account->profil],
        );
    }

    /** Le libellé est calculé AVANT la suppression : le compte n'existe plus ensuite. */
    public function accountDeleted(string $accountId, string $accountLabel): void
    {
        $this->record(
            ActivityEvent::AccountDeleted,
            null,
            "a supprimé le compte {$accountLabel}",
            ['account_id' => $accountId],
        );
    }

    public function roleCreated(Role $role): void
    {
        $this->record(ActivityEvent::RoleCreated, $role, "a créé le rôle « {$this->roleName($role)} »");
    }

    public function roleUpdated(Role $role): void
    {
        $this->record(ActivityEvent::RoleUpdated, $role, "a modifié le rôle « {$this->roleName($role)} » et ses permissions");
    }

    public function roleDeleted(string $roleId, string $roleName): void
    {
        $this->record(ActivityEvent::RoleDeleted, null, "a supprimé le rôle « {$roleName} »", ['role_id' => $roleId]);
    }

    public function roleName(Role $role): string
    {
        return $role->label ?: $role->name;
    }

    public function accountLabel(User $account): string
    {
        $profil = Profil::tryFrom((string) $account->profil)?->label();

        return trim(($profil ? mb_strtolower($profil).' de ' : 'de ').($account->name ?? $account->email ?? 'sans nom'));
    }

    // ----- Agents -------------------------------------------------------------------------

    public function driverContractUpdated(DriverContract $contract): void
    {
        $this->record(
            ActivityEvent::DriverContractUpdated,
            $contract,
            "a modifié le contrat de {$this->driverName($contract->driver)}",
        );
    }

    public function driverContractEnded(DriverContract $contract, ?string $reason): void
    {
        $label = DriverContractEndReason::tryFrom((string) $reason)?->label();

        $this->record(
            ActivityEvent::DriverContractEnded,
            $contract,
            "a terminé le contrat de {$this->driverName($contract->driver)}".($label ? " ({$label})" : ''),
            ['reason' => $reason],
        );
    }

    public function driverContractReopened(DriverContract $contract): void
    {
        $this->record(
            ActivityEvent::DriverContractReopened,
            $contract,
            "a annulé la fin du contrat de {$this->driverName($contract->driver)}",
        );
    }

    public function driverContractDeleted(string $contractId, ?Driver $driver): void
    {
        $this->record(
            ActivityEvent::DriverContractDeleted,
            $driver,
            "a supprimé un contrat de {$this->driverName($driver)}",
            ['contract_id' => $contractId],
        );
    }

    // ----- Pauses des agents -------------------------------------------------------------

    /** L'agent demande lui-même une pause, depuis son espace. */
    public function leaveRequested(LeaveRequest $leave): void
    {
        $this->record(ActivityEvent::LeaveRequested, $leave, "a demandé une pause {$this->leavePeriod($leave)}");
    }

    public function leaveApproved(LeaveRequest $leave): void
    {
        $this->record(ActivityEvent::LeaveApproved, $leave, "a validé {$this->leaveLabel($leave)}");
    }

    public function leaveRejected(LeaveRequest $leave, ?string $reason): void
    {
        $this->record(
            ActivityEvent::LeaveRejected,
            $leave,
            "a refusé {$this->leaveLabel($leave)}".($reason ? " : « {$reason} »" : ''),
            ['reason' => $reason],
        );
    }

    public function leaveEnded(LeaveRequest $leave): void
    {
        $this->record(ActivityEvent::LeaveEnded, $leave, "a mis fin à {$this->leaveLabel($leave)}");
    }

    /** Une pause saisie par l'administrateur : en cours, ou passée (historique). */
    public function leaveAdded(LeaveRequest $leave, bool $historical): void
    {
        $this->record(
            ActivityEvent::LeaveAdded,
            $leave,
            ($historical ? 'a saisi une pause passée de ' : 'a posé une pause en cours pour ')
                .$this->driverName($leave->driver).' '.$this->leavePeriod($leave),
        );
    }

    public function leaveCorrected(LeaveRequest $leave): void
    {
        $this->record(ActivityEvent::LeaveCorrected, $leave, "a corrigé {$this->leaveLabel($leave)}");
    }

    /** Le libellé est calculé AVANT la suppression. */
    public function leaveDeleted(string $leaveId, string $leaveLabel): void
    {
        $this->record(ActivityEvent::LeaveDeleted, null, "a supprimé {$leaveLabel}", ['leave_id' => $leaveId]);
    }

    /** Tâche planifiée : UNE ligne par passage, et seulement s'il a démarré quelque chose. */
    public function leavesStarted(int $count): void
    {
        $this->record(
            ActivityEvent::LeavesStarted,
            null,
            $count === 1 ? 'a démarré 1 pause d\'agent arrivée à sa date' : "a démarré {$count} pauses d'agents arrivées à leur date",
            ['count' => $count],
            actor: self::SYSTEM,
        );
    }

    /** « la pause de Koffi Mensah (5 jours à partir du 03/10/2026) » */
    public function leaveLabel(LeaveRequest $leave): string
    {
        return "la pause de {$this->driverName($leave->driver)} {$this->leavePeriod($leave)}";
    }

    // ----- Communiqués -------------------------------------------------------

    public function announcementSent(Announcement $announcement, int $recipients): void
    {
        $this->record(ActivityEvent::AnnouncementSent, $announcement,
            "a envoyé le communiqué « {$announcement->title} » à {$recipients} destinataire(s)",
            ['recipients' => $recipients]);
    }

    public function announcementReminded(Announcement $announcement, int $recipients): void
    {
        $this->record(ActivityEvent::AnnouncementReminded, $announcement,
            "a relancé {$recipients} non-lu(s) du communiqué « {$announcement->title} »",
            ['recipients' => $recipients]);
    }

    public function announcementWithdrawn(Announcement $announcement): void
    {
        $this->record(ActivityEvent::AnnouncementWithdrawn, $announcement,
            "a retiré le communiqué « {$announcement->title} »");
    }

    public function announcementDeleted(string $title): void
    {
        $this->record(ActivityEvent::AnnouncementDeleted, null,
            "a supprimé le brouillon de communiqué « {$title} »");
    }

    private function leavePeriod(LeaveRequest $leave): string
    {
        $days = (int) $leave->requested_days;

        return '('.$days.' jour'.($days > 1 ? 's' : '').' à partir du '.$leave->start_date?->format('d/m/Y').')';
    }

    // ----- Flotte ------------------------------------------------------------------------
    //
    // Le statut et les pauses d'un véhicule ne s'écrivent plus à la main depuis le 2026-10-21 :
    // `vehicle.status_changed` et `vehicle.pause_*` ne nomment plus que des lignes anciennes.

    public function vehicleCreated(Vehicle $vehicle): void
    {
        $this->record(ActivityEvent::VehicleCreated, $vehicle, "a ajouté le véhicule {$vehicle->vehicle_number}");
    }

    public function vehicleUpdated(Vehicle $vehicle): void
    {
        $this->record(ActivityEvent::VehicleUpdated, $vehicle, "a modifié le véhicule {$vehicle->vehicle_number}");
    }

    public function vehicleDeleted(string $vehicleId, string $vehicleNumber): void
    {
        $this->record(
            ActivityEvent::VehicleDeleted,
            null,
            "a supprimé le véhicule {$vehicleNumber}",
            ['vehicle_id' => $vehicleId, 'vehicle_number' => $vehicleNumber],
        );
    }

    public function vehicleContractCreated(VehicleContract $contract): void
    {
        $this->record(
            ActivityEvent::VehicleContractCreated,
            $contract->vehicle,
            "a créé le contrat de {$contract->contract_months} mois du véhicule {$contract->vehicle?->vehicle_number}",
            ['contract_id' => $contract->id],
        );
    }

    public function internalAssignmentCreated(InternalAssignment $assignment): void
    {
        $assignment->loadMissing('driver.user', 'vehicleContract.vehicle');
        $this->record(
            ActivityEvent::InternalAssignmentCreated,
            $assignment->vehicleContract,
            "a affecté {$this->driverName($assignment->driver)} en interne sur {$assignment->vehicleContract?->vehicle?->vehicle_number}",
            ['internal_assignment_id' => $assignment->id, 'start_date' => $assignment->start_date->toDateString()],
        );
    }

    /** `$successor` : l'agent dont le contrat a terminé l'affectation, s'il y en a un. */
    public function internalAssignmentEnded(InternalAssignment $assignment, ?Driver $successor = null): void
    {
        $assignment->loadMissing('driver.user', 'vehicleContract.vehicle');
        $vehicle = $assignment->vehicleContract?->vehicle?->vehicle_number;
        $description = $successor
            ? "l'affectation interne de {$this->driverName($assignment->driver)} sur {$vehicle} a été terminée par le contrat de {$this->driverName($successor)}"
            : "a terminé l'affectation interne de {$this->driverName($assignment->driver)} sur {$vehicle}";

        $this->record(
            ActivityEvent::InternalAssignmentEnded,
            $assignment->vehicleContract,
            $description,
            ['internal_assignment_id' => $assignment->id, 'end_date' => $assignment->end_date?->toDateString()],
        );
    }

    // ----- Préparation du véhicule (2026-10-20) — objet : le contrat ------------------------

    public function preparationStepDone(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationStepDone, $step, "a coché « {$step->label} » (faite le {$step->done_on?->format('d/m/Y')})");
    }

    public function preparationStepRedated(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationStepRedated, $step, "a daté « {$step->label} » du {$step->done_on?->format('d/m/Y')}");
    }

    public function preparationStepUndone(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationStepUndone, $step, "a décoché « {$step->label} »");
    }

    public function preparationStepAdded(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationStepAdded, $step, "a ajouté l'étape « {$step->label} »");
    }

    public function preparationStepRenamed(VehicleContractPreparationStep $step, string $previous): void
    {
        $this->preparation(ActivityEvent::PreparationStepRenamed, $step, "a renommé l'étape « {$previous} » en « {$step->label} »");
    }

    public function preparationStepRemoved(VehicleContract $contract, string $label): void
    {
        $contract->loadMissing('vehicle');
        $this->record(ActivityEvent::PreparationStepRemoved, $contract, "a retiré l'étape « {$label} » de la préparation de {$contract->vehicle?->vehicle_number}", ['label' => $label]);
    }

    public function preparationProofAttached(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationProofAttached, $step, "a joint une preuve à « {$step->label} »");
    }

    public function preparationProofRemoved(VehicleContractPreparationStep $step): void
    {
        $this->preparation(ActivityEvent::PreparationProofRemoved, $step, "a retiré la preuve de « {$step->label} »");
    }

    private function preparation(ActivityEvent $event, VehicleContractPreparationStep $step, string $what): void
    {
        $contract = $step->vehicleContract()->with('vehicle')->first();
        $this->record($event, $contract, "{$what} sur la préparation de {$contract?->vehicle?->vehicle_number}", [
            'step_id' => $step->id, 'label' => $step->label, 'done_on' => $step->done_on?->toDateString(),
        ]);
    }

    public function vehicleContractActivated(VehicleContract $contract, Driver $driver): void
    {
        $contract->loadMissing('vehicle');
        $driver->loadMissing('user');
        $this->record(
            ActivityEvent::VehicleContractActivated,
            $contract,
            "le contrat de {$contract->vehicle?->vehicle_number} a commencé le {$contract->start_date->format('d/m/Y')} avec {$this->driverName($driver)}",
            ['start_date' => $contract->start_date->toDateString()],
        );
    }

    /** @param  'revenue'|'charges'  $target */
    public function vehicleContractTargetReached(VehicleContract $contract, string $target, string $monthKey): void
    {
        $contract->loadMissing('vehicle');
        $what = $target === 'revenue' ? 'le revenu total' : 'le total des charges';
        $this->record(
            ActivityEvent::VehicleContractTargetReached,
            $contract,
            "{$what} de {$contract->vehicle?->vehicle_number} est atteint (fiche de ".Carbon::parse($monthKey.'-01')->translatedFormat('F Y').')',
            ['target' => $target, 'month' => $monthKey],
        );
    }

    public function vehicleContractCompleted(VehicleContract $contract): void
    {
        $contract->loadMissing('vehicle');
        $this->record(
            ActivityEvent::VehicleContractCompleted,
            $contract,
            "a soldé le contrat de {$contract->vehicle?->vehicle_number} au {$contract->end_date->format('d/m/Y')}",
            ['end_date' => $contract->end_date->toDateString()],
        );
    }

    public function vehicleContractUpdated(VehicleContract $contract): void
    {
        $this->record(
            ActivityEvent::VehicleContractUpdated,
            $contract->vehicle,
            "a modifié le contrat du véhicule {$contract->vehicle?->vehicle_number}",
            ['contract_id' => $contract->id],
        );
    }

    public function vehicleContractInvestedAmountUpdated(VehicleContract $contract, ?float $previous, float $amount): void
    {
        $contract->loadMissing('vehicle');
        $from = $previous !== null ? number_format($previous, 0, ',', ' ').' F' : 'aucun';
        $this->record(
            ActivityEvent::VehicleContractInvestedAmountUpdated,
            $contract,
            "a corrigé le montant investi du contrat de {$contract->vehicle?->vehicle_number} : {$from} → ".number_format($amount, 0, ',', ' ').' F',
            ['previous' => $previous, 'invested_amount' => $amount],
        );
    }

    public function vehicleContractDeleted(string $contractId, ?Vehicle $vehicle): void
    {
        $this->record(
            ActivityEvent::VehicleContractDeleted,
            $vehicle,
            "a supprimé un contrat du véhicule {$vehicle?->vehicle_number}",
            ['contract_id' => $contractId],
        );
    }

    // ----- Paiements et commissions ------------------------------------------------------

    public function disbursementRecorded(OwnerDisbursement $d): void
    {
        $this->record(ActivityEvent::DisbursementRecorded, $d, "a enregistré {$this->disbursementLabel($d)}");
    }

    /** @param  array<string, array{from: mixed, to: mixed}>  $changes  les seuls champs changés */
    public function disbursementUpdated(OwnerDisbursement $d, array $changes): void
    {
        $this->record(ActivityEvent::DisbursementUpdated, $d, "a corrigé {$this->disbursementLabel($d)}", $changes ? ['changes' => $changes] : []);
    }

    public function disbursementCancelled(OwnerDisbursement $d): void
    {
        $this->record(ActivityEvent::DisbursementCancelled, $d, "a annulé {$this->disbursementLabel($d)}", ['reason' => $d->cancellation_reason]);
    }

    private function disbursementLabel(OwnerDisbursement $d): string
    {
        $d->loadMissing('contract.vehicle');

        return "le versement de {$this->money($d->amount)} à {$d->contract?->vehicle?->vehicle_number} du {$d->paid_on->format('d/m/Y')}";
    }

    public function paymentCreated(Payment $payment): void
    {
        $this->record(ActivityEvent::PaymentCreated, $payment, "a enregistré {$this->paymentLabel($payment)}");
    }

    public function paymentUpdated(Payment $payment): void
    {
        $this->record(ActivityEvent::PaymentUpdated, $payment, "a modifié {$this->paymentLabel($payment)}");
    }

    public function paymentValidated(Payment $payment): void
    {
        $this->record(ActivityEvent::PaymentValidated, $payment, "a validé {$this->paymentLabel($payment)}");
    }

    public function paymentCancelled(Payment $payment): void
    {
        $this->record(ActivityEvent::PaymentCancelled, $payment, "a annulé {$this->paymentLabel($payment)}");
    }

    /** Le libellé est calculé AVANT la suppression. */
    public function paymentDeleted(string $paymentId, string $paymentLabel): void
    {
        $this->record(ActivityEvent::PaymentDeleted, null, "a supprimé {$paymentLabel}", ['payment_id' => $paymentId]);
    }

    /**
     * Tâche planifiée : UNE ligne par passage, pas une par paiement — la génération du soir
     * en crée une par contrat actif et par jour ouvré, qui noieraient le journal.
     */
    public function dailyPaymentsGenerated(int $count): void
    {
        $this->record(
            ActivityEvent::DailyPaymentsGenerated,
            null,
            $count === 1 ? 'a généré 1 paiement journalier' : "a généré {$count} paiements journaliers",
            ['count' => $count],
            actor: self::SYSTEM,
        );
    }

    /** UNE ligne par génération sur une période, pas une par paiement (2026-10-01). */
    public function contractPaymentsGenerated(DriverContract $contract, string $from, string $to, int $count, float $total): void
    {
        $period = Carbon::parse($from)->format('d/m/Y').' au '.Carbon::parse($to)->format('d/m/Y');

        $this->record(
            ActivityEvent::ContractPaymentsGenerated,
            $contract,
            "a généré {$count} paiement(s) de {$this->driverName($contract->driver)} ({$this->money($total)}) pour la période du {$period}",
            ['from' => $from, 'to' => $to, 'count' => $count, 'total' => $total],
        );
    }

    /**
     * UNE ligne pour le lot, qui nomme ses véhicules, puis UNE par paiement, rattachée à lui
     * et nommant son agent : sans elles, un lot ne se retrouvait ni depuis un paiement, ni
     * par véhicule ou agent (2026-10-08).
     *
     * @param  Collection<int, Payment>  $payments  chargés avec `driver.user` et `vehicleContract.vehicle`
     */
    public function paymentsValidatedInBatch(Collection $payments, string $collectedOn): void
    {
        $date = Carbon::parse($collectedOn)->format('d/m/Y');
        $total = (float) $payments->sum('net_amount');

        $this->record(
            ActivityEvent::PaymentsBatchValidated,
            null,
            "a validé {$payments->count()} paiement(s) ({$this->money($total)}) encaissés le {$date}{$this->vehicles($payments)}",
            ['count' => $payments->count(), 'total' => $total, 'collected_on' => $collectedOn, 'payment_ids' => $payments->pluck('id')->all()],
        );

        foreach ($payments as $payment) {
            $this->record(
                ActivityEvent::PaymentValidated,
                $payment,
                "a validé {$this->batchPaymentLabel($payment)}, encaissé le {$date} (validation groupée)",
                ['collected_on' => $collectedOn],
            );
        }
    }

    /**
     * Comme la validation groupée : une ligne pour le lot, avec son motif, puis une par
     * paiement (2026-10-08).
     *
     * @param  Collection<int, Payment>  $payments  chargés avec `driver.user` et `vehicleContract.vehicle`
     */
    public function paymentsCancelledInBatch(Collection $payments, string $reason): void
    {
        $total = (float) $payments->sum('net_amount');

        $this->record(
            ActivityEvent::PaymentsBatchCancelled,
            null,
            "a annulé {$payments->count()} paiement(s) ({$this->money($total)}){$this->vehicles($payments)} : {$reason}",
            ['count' => $payments->count(), 'total' => $total, 'reason' => $reason, 'payment_ids' => $payments->pluck('id')->all()],
        );

        foreach ($payments as $payment) {
            $this->record(
                ActivityEvent::PaymentCancelled,
                $payment,
                "a annulé {$this->batchPaymentLabel($payment)} (annulation groupée) : {$reason}",
                ['reason' => $reason],
            );
        }
    }

    /** « — 2FX1662RB, 2GC4662RB » : les véhicules d'un lot, pour la recherche du journal. */
    private function vehicles(Collection $payments): string
    {
        $numbers = $payments->map(fn (Payment $p) => $p->vehicleContract?->vehicle?->vehicle_number)->filter()->unique()->sort()->values();

        return $numbers->isEmpty() ? '' : ' — '.$numbers->implode(', ');
    }

    /** Le libellé d'un paiement, avec son jour et son véhicule : un lot en compte souvent vingt. */
    private function batchPaymentLabel(Payment $payment): string
    {
        $vehicle = $payment->vehicleContract?->vehicle?->vehicle_number;

        return "le paiement du {$payment->payment_date?->format('d/m/Y')} de {$this->money($payment->amount)} de {$this->driverName($payment->driver)}"
            .($vehicle ? " ({$vehicle})" : '');
    }

    public function cancelledPaymentsPurged(int $count, float $total): void
    {
        $this->record(
            ActivityEvent::CancelledPaymentsPurged,
            null,
            "a vidé {$count} paiement(s) de contrat annulé(s) ({$this->money($total)})",
            ['count' => $count, 'total' => $total],
        );
    }

    public function commissionCancelled(Commission $commission): void
    {
        $this->record(
            ActivityEvent::CommissionCancelled,
            $commission,
            "a annulé la commission de {$this->money($commission->amount)} de {$this->driverName($commission->driver)}"
                .($commission->booking ? " (course {$commission->booking->booking_number})" : ''),
        );
    }

    public function paymentLabel(Payment $payment): string
    {
        return "le paiement de {$this->money($payment->amount)} de {$this->driverName($payment->driver)}";
    }

    // ----- Réglages ----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function pricingUpdated(array $before, array $after): void
    {
        $this->record(ActivityEvent::PricingUpdated, null, 'a modifié les tarifs des courses', $this->changes($before, $after));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function contractTermsUpdated(array $before, array $after): void
    {
        $this->record(ActivityEvent::ContractTermsUpdated, null, 'a modifié les réglages des contrats propriétaires', $this->changes($before, $after));
    }

    public function preparationStepsUpdated(array $before, array $after): void
    {
        $this->record(ActivityEvent::PreparationStepsUpdated, null, 'a modifié les étapes de préparation par défaut', $this->changes($before, $after));
    }

    // ----- Fiches de rémunération --------------------------------------------------------

    /**
     * UNE ligne par passage, et aucune quand il n'a rien créé — une relance sans doublon
     * n'a rien à dire. Au nom du « Système » pour la tâche du 1er du mois, de
     * l'administrateur pour une génération à la demande.
     */
    public function remunerationStatementsGenerated(string $monthKey, int $count): void
    {
        $month = Carbon::parse($monthKey.'-01')->locale('fr')->translatedFormat('F Y');

        $this->record(
            ActivityEvent::RemunerationStatementsGenerated,
            null,
            "a créé {$count} brouillon(s) de fiches de rémunération pour {$month}",
            ['month' => $monthKey, 'count' => $count],
        );
    }

    public function remunerationStatementValidated(RemunerationStatement $statement): void
    {
        $this->record(
            ActivityEvent::RemunerationStatementValidated,
            $statement,
            $statement->delivery === 'none'
                ? "a validé sans l'envoyer la fiche de rémunération {$statement->number} de {$this->statementOwner($statement)}"
                : "a validé la fiche de rémunération {$statement->number} de {$this->statementOwner($statement)}",
        );
    }

    /** L'envoi se fait en file, après la validation : c'est le système qui envoie. */
    public function remunerationStatementSent(RemunerationStatement $statement): void
    {
        $this->record(
            ActivityEvent::RemunerationStatementSent,
            $statement,
            "a envoyé la fiche de rémunération {$statement->number} à {$this->statementOwner($statement)}",
            actor: self::SYSTEM,
        );
    }

    /** Envoyer une fiche validée sans envoi, ou la renvoyer : l'envoi lui-même suit, en file. */
    public function remunerationStatementSendRequested(RemunerationStatement $statement, bool $resend): void
    {
        $this->record(
            ActivityEvent::RemunerationStatementSendRequested,
            $statement,
            ($resend ? 'a renvoyé' : 'a envoyé').
                " la fiche de rémunération {$statement->number} à {$this->statementOwner($statement)}",
        );
    }

    /** @param  list<string>  $numbers */
    public function cancelledStatementsPurged(array $numbers): void
    {
        $this->record(
            ActivityEvent::RemunerationStatementsPurged,
            null,
            'a vidé '.count($numbers).' fiche(s) de rémunération annulée(s) : '.implode(', ', $numbers),
            ['numbers' => $numbers],
        );
    }

    public function remunerationStatementCancelled(RemunerationStatement $statement, string $reason): void
    {
        $this->record(
            ActivityEvent::RemunerationStatementCancelled,
            $statement,
            "a annulé la fiche de rémunération {$statement->number} de {$this->statementOwner($statement)} : {$reason}",
        );
    }

    private function statementOwner(RemunerationStatement $statement): string
    {
        return $statement->contract?->vehicle?->owner?->name ?? 'un propriétaire';
    }

    // ----- Plomberie ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $properties
     * @param  string|null  $actor  l'auteur affiché ; par défaut, l'utilisateur de la requête,
     *                              sinon « Système ». Vide pour une ligne sans auteur.
     */
    private function record(
        ActivityEvent $event,
        ?Model $subject,
        string $description,
        array $properties = [],
        ?User $causer = null,
        ?string $actor = null,
    ): void {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $causer ??= $request?->user() instanceof User ? $request->user() : null;
            $actor ??= $causer?->name ?? self::SYSTEM;

            $entry = activity('activity')
                ->event($event->value)
                ->withProperties(array_filter([
                    'actor' => $actor,
                    'ip' => $request?->ip(),
                    ...$properties,
                ], fn ($value) => $value !== null && $value !== ''));

            if ($causer) {
                $entry->causedBy($causer);
            }
            if ($subject) {
                $entry->performedOn($subject);
            }

            $entry->log($description);
        } catch (\Throwable $e) {
            Log::error('Journal d\'activité : trace non écrite ('.$event->value.') : '.$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Les seuls champs qui ont changé, avant et après.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{changes?: array<string, array{from: mixed, to: mixed}>}
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $changes ? ['changes' => $changes] : [];
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 0, ',', ' ').' FCFA';
    }

    private function driverName(?Driver $driver): string
    {
        return $driver?->user?->name ?? 'un agent';
    }

    private function statusLabel(string $status): string
    {
        return BookingStatus::tryFrom($status)?->label() ?? $status;
    }
}

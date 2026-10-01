<?php

namespace App\Domains\Audit\Domain;

/**
 * Le catalogue des événements du journal d'activité.
 *
 * Le code (`booking.driver_assigned`) est ce qui se filtre et se stocke ; le libellé est
 * celui du filtre de l'écran. Un événement ajouté ici doit l'être aussi dans
 * `ActivityJournal`, qui en écrit la phrase.
 */
enum ActivityEvent: string
{
    // Connexions et comptes
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case SessionRevoked = 'auth.session_revoked';
    case OtherSessionsRevoked = 'auth.other_sessions_revoked';
    case PasswordChanged = 'auth.password_changed';
    case PasswordReset = 'auth.password_reset';

    // Réservations
    case BookingCreated = 'booking.created';
    case BookingUpdated = 'booking.updated';
    case BookingDeleted = 'booking.deleted';
    case BookingDriverAssigned = 'booking.driver_assigned';
    case BookingDriverRemoved = 'booking.driver_removed';
    case BookingStatusChanged = 'booking.status_changed';
    case BookingReopened = 'booking.reopened';
    case BookingAccepted = 'booking.accepted';
    case BookingStarted = 'booking.started';
    case BookingCompleted = 'booking.completed';
    case BookingCancelled = 'booking.cancelled';
    case BookingExpired = 'booking.expired';
    case SubscriptionRevoked = 'booking.subscription_revoked';
    case SubscriptionTransferred = 'booking.subscription_transferred';
    case SubscriptionTerminated = 'booking.subscription_terminated';

    // Comptes (propriétaires, agents, administrateurs) et rôles
    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountStatusChanged = 'account.status_changed';
    case AccountPasswordSet = 'account.password_set';
    case AccountDeleted = 'account.deleted';
    case RoleCreated = 'account.role_created';
    case RoleUpdated = 'account.role_updated';
    case RoleDeleted = 'account.role_deleted';

    // Agents : disponibilité et contrats
    case DriverAvailabilityChanged = 'driver.availability_changed';
    case DriverContractUpdated = 'driver.contract_updated';
    case DriverContractEnded = 'driver.contract_ended';
    case DriverContractDeleted = 'driver.contract_deleted';

    // Pauses des agents
    case LeaveRequested = 'leave.requested';
    case LeaveApproved = 'leave.approved';
    case LeaveRejected = 'leave.rejected';
    case LeaveEnded = 'leave.ended';
    case LeaveAdded = 'leave.added';
    case LeaveCorrected = 'leave.corrected';
    case LeaveDeleted = 'leave.deleted';
    case LeavesStarted = 'leave.started';

    // Flotte : véhicules, pauses véhicule, contrats véhicule
    case VehicleCreated = 'vehicle.created';
    case VehicleUpdated = 'vehicle.updated';
    case VehicleStatusChanged = 'vehicle.status_changed';
    case VehicleDeleted = 'vehicle.deleted';
    case VehiclePaused = 'vehicle.paused';
    case VehiclePauseEnded = 'vehicle.pause_ended';
    case VehiclePauseCancelled = 'vehicle.pause_cancelled';
    case VehicleContractCreated = 'vehicle.contract_created';
    case VehicleContractUpdated = 'vehicle.contract_updated';
    case VehicleContractDeleted = 'vehicle.contract_deleted';

    // Paiements et commissions
    case PaymentCreated = 'payment.created';
    case PaymentUpdated = 'payment.updated';
    case PaymentValidated = 'payment.validated';
    case PaymentCancelled = 'payment.cancelled';
    case PaymentDeleted = 'payment.deleted';
    case DailyPaymentsGenerated = 'payment.daily_generated';
    case ContractPaymentsGenerated = 'payment.period_generated';
    case PaymentsBatchValidated = 'payment.batch_validated';
    case PaymentsBatchCancelled = 'payment.batch_cancelled';
    case CancelledPaymentsPurged = 'payment.cancelled_purged';
    case CommissionCancelled = 'payment.commission_cancelled';

    // Fiches de rémunération des propriétaires
    case RemunerationStatementsGenerated = 'remuneration_statement.generated';
    case RemunerationStatementValidated = 'remuneration_statement.validated';
    case RemunerationStatementSent = 'remuneration_statement.sent';
    case RemunerationStatementCancelled = 'remuneration_statement.cancelled';
    case RemunerationStatementsPurged = 'remuneration_statement.purged';

    // Réglages
    case PricingUpdated = 'settings.pricing_updated';
    case ContractTermsUpdated = 'settings.contract_terms_updated';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Connexion',
            self::LoginFailed => 'Connexion refusée',
            self::Logout => 'Déconnexion',
            self::SessionRevoked => 'Appareil déconnecté',
            self::OtherSessionsRevoked => 'Autres appareils déconnectés',
            self::PasswordChanged => 'Mot de passe changé',
            self::PasswordReset => 'Mot de passe réinitialisé',
            self::BookingCreated => 'Réservation créée',
            self::BookingUpdated => 'Réservation modifiée',
            self::BookingDeleted => 'Réservation supprimée',
            self::BookingDriverAssigned => 'Agent affecté',
            self::BookingDriverRemoved => 'Agent retiré',
            self::BookingStatusChanged => 'Statut changé',
            self::BookingReopened => 'Course rouverte',
            self::BookingAccepted => 'Course acceptée',
            self::BookingStarted => 'Course démarrée',
            self::BookingCompleted => 'Course terminée',
            self::BookingCancelled => 'Course annulée',
            self::BookingExpired => 'Réservation expirée',
            self::SubscriptionRevoked => 'Abonnement rendu',
            self::SubscriptionTransferred => 'Abonnement transféré',
            self::SubscriptionTerminated => 'Abonnement résilié',
            self::AccountCreated => 'Compte créé',
            self::AccountUpdated => 'Compte modifié',
            self::AccountStatusChanged => 'Compte activé ou désactivé',
            self::AccountPasswordSet => 'Mot de passe défini par un admin',
            self::AccountDeleted => 'Compte supprimé',
            self::RoleCreated => 'Rôle créé',
            self::RoleUpdated => 'Rôle modifié',
            self::RoleDeleted => 'Rôle supprimé',
            self::DriverAvailabilityChanged => 'Disponibilité d\'un agent',
            self::DriverContractUpdated => 'Contrat agent modifié',
            self::DriverContractEnded => 'Contrat agent terminé',
            self::DriverContractDeleted => 'Contrat agent supprimé',
            self::LeaveRequested => 'Pause demandée',
            self::LeaveApproved => 'Pause validée',
            self::LeaveRejected => 'Pause refusée',
            self::LeaveEnded => 'Pause terminée',
            self::LeaveAdded => 'Pause saisie par un admin',
            self::LeaveCorrected => 'Pause corrigée',
            self::LeaveDeleted => 'Pause supprimée',
            self::LeavesStarted => 'Pauses démarrées',
            self::VehicleCreated => 'Véhicule ajouté',
            self::VehicleUpdated => 'Véhicule modifié',
            self::VehicleStatusChanged => 'Véhicule activé ou désactivé',
            self::VehicleDeleted => 'Véhicule supprimé',
            self::VehiclePaused => 'Véhicule mis en pause',
            self::VehiclePauseEnded => 'Pause véhicule terminée',
            self::VehiclePauseCancelled => 'Pause véhicule annulée',
            self::VehicleContractCreated => 'Contrat véhicule créé',
            self::VehicleContractUpdated => 'Contrat véhicule modifié',
            self::VehicleContractDeleted => 'Contrat véhicule supprimé',
            self::PaymentCreated => 'Paiement enregistré',
            self::PaymentUpdated => 'Paiement modifié',
            self::PaymentValidated => 'Paiement validé',
            self::PaymentCancelled => 'Paiement annulé',
            self::PaymentDeleted => 'Paiement supprimé',
            self::DailyPaymentsGenerated => 'Paiements journaliers générés',
            self::ContractPaymentsGenerated => 'Paiements générés sur une période',
            self::PaymentsBatchValidated => 'Paiements validés en groupe',
            self::PaymentsBatchCancelled => 'Paiements annulés en groupe',
            self::CancelledPaymentsPurged => 'Paiements annulés vidés',
            self::CommissionCancelled => 'Commission annulée',
            self::RemunerationStatementsGenerated => 'Fiches de rémunération générées',
            self::RemunerationStatementValidated => 'Fiche de rémunération validée',
            self::RemunerationStatementSent => 'Fiche de rémunération envoyée',
            self::RemunerationStatementCancelled => 'Fiche de rémunération annulée',
            self::RemunerationStatementsPurged => 'Fiches annulées vidées',
            self::PricingUpdated => 'Tarifs modifiés',
            self::ContractTermsUpdated => 'Réglages des contrats modifiés',
        };
    }

    /**
     * Le groupe du filtre de l'écran.
     *
     * ⚠️ Un groupe = un PRÉFIXE de code : l'écran filtre un groupe entier par `booking.`,
     * `vehicle.`… C'est pourquoi les contrats véhicule sont `vehicle.contract_*` et les
     * commissions `payment.commission_*` : un préfixe à part les sortirait de leur groupe.
     */
    public function group(): string
    {
        return match (strtok($this->value, '.')) {
            'auth' => 'Connexions',
            'account' => 'Comptes et rôles',
            'driver' => 'Agents',
            'leave' => 'Pauses',
            'booking' => 'Réservations',
            'vehicle' => 'Flotte',
            'payment' => 'Paiements',
            'remuneration_statement' => 'Fiches de rémunération',
            default => 'Réglages',
        };
    }
}

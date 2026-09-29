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

    // Comptes (propriétaires ici ; agents et administrateurs au lot 3)
    case AccountCreated = 'account.created';
    case AccountUpdated = 'account.updated';
    case AccountStatusChanged = 'account.status_changed';
    case AccountPasswordSet = 'account.password_set';
    case AccountDeleted = 'account.deleted';

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
    case CommissionCancelled = 'payment.commission_cancelled';

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
            self::CommissionCancelled => 'Commission annulée',
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
            'account' => 'Comptes',
            'booking' => 'Réservations',
            'vehicle' => 'Flotte',
            'payment' => 'Paiements',
            default => 'Réglages',
        };
    }
}

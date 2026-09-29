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
            self::PricingUpdated => 'Tarifs modifiés',
            self::ContractTermsUpdated => 'Réglages des contrats modifiés',
        };
    }

    /** Le groupe du filtre de l'écran. */
    public function group(): string
    {
        return match (true) {
            str_starts_with($this->value, 'auth.') => 'Connexions et comptes',
            str_starts_with($this->value, 'booking.') => 'Réservations',
            default => 'Réglages',
        };
    }
}

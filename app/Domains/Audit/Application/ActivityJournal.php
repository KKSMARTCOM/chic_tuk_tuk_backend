<?php

namespace App\Domains\Audit\Application;

use App\Domains\Audit\Domain\ActivityEvent;
use App\Domains\Booking\Domain\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
        $this->record(ActivityEvent::ContractTermsUpdated, null, 'a modifié les réglages des contrats véhicule', $this->changes($before, $after));
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

    private function driverName(?Driver $driver): string
    {
        return $driver?->user?->name ?? 'un agent';
    }

    private function statusLabel(string $status): string
    {
        return BookingStatus::tryFrom($status)?->label() ?? $status;
    }
}

<?php

namespace App\Domains\Booking\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Statuts d'une course.
 *
 * Source de vérité : contrainte CHECK `bookings_status_check` en base.
 * Le statut « suspended » mentionné dans la documentation n'existe ni en base ni dans
 * le code — il n'est pas repris ici.
 */
enum BookingStatus: string
{
    use HasOptions;

    case Pending    = 'pending';
    case Confirmed  = 'confirmed';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
    case Expired    = 'expired';
    // Course enfant d'abonnement que personne n'a prise : rattrapée en fin d'abonnement.
    case Missed     = 'missed';

    /** Libellés repris à l'identique des vues Blade (accord au féminin : « une course »). */
    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'En attente',
            self::Confirmed  => 'Confirmée',
            self::InProgress => 'En cours',
            self::Completed  => 'Terminée',
            self::Cancelled  => 'Annulée',
            self::Expired    => 'Expirée',
            self::Missed     => 'Non traitée',
        };
    }

    /**
     * Classes Tailwind du badge de statut, reprises des vues Blade.
     * Exposées à l'API pour que le front Nuxt reproduise le rendu actuel à l'identique.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending    => 'bg-yellow-100 text-yellow-800',
            self::Confirmed  => 'bg-blue-100 text-blue-800',
            self::InProgress => 'bg-indigo-100 text-indigo-800',
            self::Completed  => 'bg-green-100 text-green-800',
            self::Cancelled  => 'bg-red-100 text-red-800',
            self::Expired    => 'bg-gray-100 text-gray-800',
            self::Missed     => 'bg-orange-100 text-orange-800',
        };
    }

    /** Une course dans un état terminal ne peut plus changer de statut. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired, self::Missed], true);
    }
}

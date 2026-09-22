<?php

namespace App\Domains\Booking\Application\Data\Concerns;

use App\Models\Booking;

/**
 * La NATURE d'une course, tranchée une fois pour toutes.
 *
 * ⚠️ La vue Blade enchaîne quatre tests d'accesseurs — `is_subscription_parent`,
 * `is_subscription_child`, `is_simple_return`, puis le cas par défaut — pour choisir son
 * badge. Ces accesseurs se RECOUPENT : un enfant d'abonnement porte un
 * `parent_booking_id` comme un retour de course simple, et c'est l'ORDRE des tests qui
 * les départage. Inverser deux d'entre eux changerait le badge d'une partie des courses
 * sans rien casser de visible.
 *
 * Le front ne doit pas avoir à reproduire cet ordre, et la liste comme le détail doivent
 * répondre la même chose : la règle vit donc ici, à un seul endroit.
 */
trait DescribesBookingKind
{
    /** `single` | `subscription_parent` | `subscription_child` | `simple_return`. */
    private static function kind(Booking $booking): string
    {
        return match (true) {
            $booking->is_subscription_parent => 'subscription_parent',
            $booking->is_subscription_child => 'subscription_child',
            $booking->is_simple_return => 'simple_return',
            default => 'single',
        };
    }

    /**
     * Le libellé que le Blade affiche dans le badge.
     *
     * ⚠️ `Booking::subscription_label` ne couvre PAS le retour d'une course simple : il
     * rend « Course unique » pour lui, alors que le Blade affiche « Retour — <client> ».
     * Le cas est traité ici plutôt que dans l'accesseur, que l'espace agent emploie aussi
     * et dont le sens ne doit pas changer sous ses pieds.
     */
    private static function kindLabel(Booking $booking): string
    {
        if (! $booking->is_simple_return) {
            return $booking->subscription_label;
        }

        $parent = $booking->parentBooking;
        $qui = $parent?->client_name
            ?? $parent?->user?->name
            ?? $parent?->booking_number
            ?? 'course inconnue';

        return "Retour — {$qui}";
    }
}

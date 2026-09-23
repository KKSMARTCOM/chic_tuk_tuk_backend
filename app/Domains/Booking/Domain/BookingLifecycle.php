<?php

namespace App\Domains\Booking\Domain;

use App\Models\Booking;

/**
 * Ce qu'une réservation permet de faire d'elle, selon où elle en est.
 *
 * ## Pourquoi cette classe existe
 *
 * Le contrôleur Blade validait `status` par `in:pending,confirmed,in_progress,completed,
 * cancelled` et rien d'autre : n'IMPORTE quel statut pouvait donc suivre n'importe quel
 * autre. On pouvait remettre « En attente » une course terminée, ou reprendre une course
 * annulée. Signalé le 2026-09-22.
 *
 * Les trois règles d'action, elles, existaient bien — mais éparpillées en conditions de
 * gabarit (`$canAssign`, `$canRemoveDriver`, `$canDelete` en tête de `show.blade.php`),
 * donc invisibles de l'API et impossibles à tester. Un appel direct les contournait.
 *
 * Tout est réuni ici, et le serveur RÉPOND ce qu'il autorise : le front affiche ce qu'on
 * lui dit, il ne recalcule rien. Une seconde version de ces règles dans un `v-if`
 * divergerait au premier changement.
 */
final class BookingLifecycle
{
    /**
     * Les statuts qui CLÔTURENT une réservation. Rien n'en sort.
     *
     * ⚠️ C'est le point de la correction : une course terminée a produit une commission
     * et un gain d'agent, une course annulée a pu être recréée ailleurs, une course
     * expirée a raté son heure. Les rouvrir ne défait aucun de ces effets — cela crée
     * seulement une réservation dont l'historique ment.
     *
     * ⚠️ Le prix à payer : une clôture faite par erreur ne se rattrape plus depuis
     * l'écran. C'est délibéré — la seule issue honnête serait une action « annuler la
     * clôture » qui défasse la commission, pas un menu de statuts.
     */
    public const TERMINAL = ['completed', 'cancelled', 'expired'];

    /**
     * Les transitions permises, depuis chaque statut.
     *
     * ⚠️ `confirmed → pending` n'y est PAS, alors que le Blade l'autorisait : il
     * laisserait une course « en attente » avec un agent encore affecté, deux
     * informations qui se contredisent. Le chemin de retour est « Retirer l'agent », qui
     * fait les deux d'un coup.
     */
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'expired' => [],
    ];

    /** @return array<int, string> */
    public static function allowedStatusesFrom(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Un agent peut-il être AFFECTÉ à cette course ?
     *
     * Repris de `$canAssign` dans `show.blade.php`, mot pour mot :
     *
     *  - la course n'a pas déjà d'agent ;
     *  - elle est `pending` — une course confirmée en a déjà un, une course close n'en a
     *    plus besoin ;
     *  - ⚠️ ce n'est PAS une course fille d'abonnement. Celles-là reviennent au TITULAIRE
     *    de l'abonnement ; leur affecter quelqu'un d'autre briserait la chaîne, et le
     *    prochain enfant créé par le cron repartirait quand même vers le titulaire.
     */
    public static function canAssignDriver(Booking $booking): bool
    {
        return ! $booking->driver_id
            && $booking->status === 'pending'
            && ! $booking->is_subscription_child;
    }

    /**
     * L'agent peut-il être RETIRÉ ?
     *
     * Repris de `$canRemoveDriver` : il faut un agent, et une course qui n'est pas close.
     * Retirer l'agent d'une course terminée effacerait celui à qui la commission a été
     * versée.
     */
    public static function canRemoveDriver(Booking $booking): bool
    {
        return (bool) $booking->driver_id && ! self::isTerminal($booking->status);
    }

    /**
     * Les DÉTAILS de la course peuvent-ils être modifiés (trajet, horaires, prix) ?
     *
     * ⚠️ Repris de la garde qui entoure le lien « Modifier », identique sur la liste et
     * sur le dossier : `status === 'pending'`. Rien d'autre — y compris une course fille
     * d'abonnement, que le Blade laisse éditer tant qu'elle est en attente.
     *
     * ⚠️ Ce n'est PAS un chemin de changement de statut. `UpdateAdminBooking` n'accepte
     * jamais de champ `status` : le seul chemin qui en change est `ChangeBookingStatus`,
     * qui applique la matrice de transitions et notifie. Le contrôleur Blade, lui,
     * validait `status` dans le MÊME formulaire que l'édition du trajet — un
     * administrateur pouvait donc confirmer ou annuler une course sans jamais passer par
     * la logique déjà en place pour ce geste.
     */
    public static function canEdit(Booking $booking): bool
    {
        return $booking->status === 'pending';
    }

    /**
     * La CLÔTURE peut-elle être annulée ?
     *
     * ⚠️ C'est la seule issue d'une course terminée, et elle ne passe pas par la matrice
     * de transitions : celle-ci ne décrit que des changements d'ÉTIQUETTE, alors que
     * rouvrir défait la commission, le gain de l'agent et son compteur de trajets. Une
     * clôture faite par erreur se rattrape donc, mais en défaisant, jamais en renommant.
     */
    public static function canReopen(Booking $booking): bool
    {
        return $booking->status === 'completed';
    }

    /**
     * La réservation peut-elle être SUPPRIMÉE ?
     *
     * ⚠️ Repris de `$canDelete` : seulement `cancelled` ou `expired`. Une course vivante
     * se termine ou s'annule ; une course terminée porte une ligne comptable que sa
     * suppression laisserait orpheline. La suppression n'est là que pour faire le ménage
     * de ce qui n'a pas eu lieu.
     */
    public static function canDelete(Booking $booking): bool
    {
        return in_array($booking->status, ['cancelled', 'expired'], true);
    }
}

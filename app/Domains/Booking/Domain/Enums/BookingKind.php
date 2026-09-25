<?php

namespace App\Domains\Booking\Domain\Enums;

/**
 * La nature d'une course, telle que l'API la rend dans `kind` — voir
 * `DescribesBookingKind`, qui la calcule depuis les accesseurs du modèle.
 *
 * Il n'existe pas de colonne `kind` en base : cet énumérateur sert à nommer les quatre
 * valeurs possibles, et à les donner au front par la génération des types TypeScript.
 */
enum BookingKind: string
{
    case Single = 'single';
    case SubscriptionParent = 'subscription_parent';
    case SubscriptionChild = 'subscription_child';
    case SimpleReturn = 'simple_return';
}

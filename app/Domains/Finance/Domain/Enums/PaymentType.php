<?php

namespace App\Domains\Finance\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Nature d'un paiement — colonne `payments.payment_type`.
 *
 * Source de vérité : contrainte CHECK `payments_payment_type_check`, qui autorise
 * cinq valeurs. Les règles de validation actuelles en exposent trois (commission,
 * contract, subscription_revenue) ; « bonus » et « other » restent réservés.
 *
 * ⚠️ `subscription_revenue` manquait ici alors que la migration du 2026-09-23 l'avait
 * ajouté à la contrainte : relevé le 2026-09-25 en générant les types du front.
 */
enum PaymentType: string
{
    use HasOptions;

    case Commission = 'commission';
    case Contract   = 'contract';
    case Bonus      = 'bonus';
    case Other      = 'other';
    // Ce que l'agence doit à un agent pour les courses d'abonnement qu'il a faites.
    case SubscriptionRevenue = 'subscription_revenue';

    public function label(): string
    {
        return match ($this) {
            self::Commission => 'Commission',
            self::Contract   => 'Contrat',
            self::Bonus      => 'Prime',
            self::Other      => 'Autre',
            self::SubscriptionRevenue => 'Revenu abonnement',
        };
    }
}

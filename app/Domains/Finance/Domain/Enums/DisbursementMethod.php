<?php

namespace App\Domains\Finance\Domain\Enums;

/** Le moyen d'un versement au propriétaire. Valeurs = contrainte CHECK de `owner_disbursements`. */
enum DisbursementMethod: string
{
    case MobileMoney = 'mobile_money';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money',
            self::BankTransfer => 'Virement',
        };
    }
}

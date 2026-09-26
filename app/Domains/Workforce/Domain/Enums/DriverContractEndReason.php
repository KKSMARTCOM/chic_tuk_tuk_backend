<?php

namespace App\Domains\Workforce\Domain\Enums;

use App\Shared\Enums\HasOptions;

/**
 * Raison de fin d'un contrat agent — colonne `driver_contracts.end_reason`, chaîne libre
 * en base. Les quatre premières viennent de la modale « Terminer le contrat » ;
 * `new_contract` est posée par `DriverContractService::create()` quand un nouveau contrat
 * remplace l'actif sur le même véhicule.
 */
enum DriverContractEndReason: string
{
    use HasOptions;

    case Resignation = 'demission';
    case Abandonment = 'abandon';
    case EndOfContract = 'fin_contrat';
    case Other = 'autre';
    case NewContract = 'new_contract';

    public function label(): string
    {
        return match ($this) {
            self::Resignation => 'Démission',
            self::Abandonment => 'Abandon',
            self::EndOfContract => 'Fin de contrat',
            self::Other => 'Autre',
            self::NewContract => 'Nouveau contrat',
        };
    }

    /** Les raisons qu'on choisit en terminant un contrat. */
    public static function selectable(): array
    {
        return [self::Resignation->value, self::Abandonment->value, self::EndOfContract->value, self::Other->value];
    }
}

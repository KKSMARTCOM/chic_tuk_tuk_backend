<?php

namespace App\Domains\Workforce\Domain;

use App\Models\DriverContract;

/**
 * Les règles d'un contrat agent partagées par sa modification et sa suppression.
 *
 * Déplacées de `DriverContractService` le 2026-09-27, sans changement.
 */
final class DriverContractRules
{
    /** Des pauses agent ou des paiements : le contrat a servi. */
    public static function hasHistory(DriverContract $contract): bool
    {
        return $contract->leaveRequests()->exists() || $contract->payments()->exists();
    }
}

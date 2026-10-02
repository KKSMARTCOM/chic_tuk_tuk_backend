<?php

namespace App\Domains\Finance\Application\Data;

use App\Shared\Data\BaseData;

/**
 * La « Situation de l'agent » (2026-10-02), sur la fiche d'un paiement comme dans son
 * dossier : ce qu'il doit en commissions, où en sont ses paiements de contrat, et ce que
 * l'agence lui doit sur ses abonnements — `BuildDriverSituation`.
 */
final class AdminDriverSituationData extends BaseData
{
    public function __construct(
        public AdminDriverCommissionSituationData $commissions,
        /** Le contrat agent en cours, à défaut le dernier ; `null` sans aucun contrat. */
        public ?AdminDriverContractSituationData $contract,
        public AdminDriverSubscriptionSituationData $subscriptions,
    ) {}
}

<?php

namespace App\Domains\Fleet\Application\Data;

use App\Shared\Data\BaseData;

/** Les quatre chiffres en tête du dossier propriétaire (spec 2026-11-04). */
final class AdminOwnerDossierTotalsData extends BaseData
{
    public function __construct(
        /** Les versements non annulés, tous contrats (`BuildDisbursementSummary`). */
        public float $disbursed,
        /** Sur les contrats en attente et en cours : total moins ce qui est payé. */
        public float $remaining,
        /** Fiches validées moins versements, le calcul de la fiche du contrat. */
        public float $outstanding,
        public int $vehiclesInService,
        public int $vehiclesTotal,
    ) {}
}

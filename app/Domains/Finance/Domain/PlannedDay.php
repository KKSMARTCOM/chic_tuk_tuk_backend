<?php

namespace App\Domains\Finance\Domain;

/** Un jour d'une période de génération, classé une seule fois. */
final class PlannedDay
{
    public function __construct(
        /** `Y-m-d` */
        public readonly string $date,
        public readonly string $class,
        /** Le paiement qui rend le jour `paid` ou `cancelled` — un lien dans l'aperçu. */
        public readonly ?string $paymentId = null,
    ) {}
}

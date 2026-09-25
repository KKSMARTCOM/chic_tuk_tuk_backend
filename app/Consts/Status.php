<?php

namespace App\Consts;

class Status
{
    public const BOOKING_STATUES = [
        'PENDING' => 'pending',
        'CONFIRMED' => 'confirmed',
        'IN_PROGRESS' => 'in_progress',
        'COMPLETED'  => 'completed',
        'CANCELLED'  => 'cancelled',
        'EXPIRED'    => 'expired',
        // Course enfant d'abonnement que personne n'a prise : rattrapée en fin d'abonnement.
        'MISSED'     => 'missed',
    ];
}

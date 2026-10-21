<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Models\Driver;

/**
 * Recalcule la disponibilité d'un agent (spec 2026-10-21, §4.1) — le SEUL endroit où elle se
 * décide.
 *
 * Disponible ⇔ un travail (contrat agent actif, ou affectation interne en cours) ET aucune
 * pause agent COMMENCÉE. Une pause `ongoing` dont le début est à venir ne bloque personne :
 * c'est la date qui décide, pas le statut.
 *
 * ⚠️ Avant, chaque flux écrivait `is_available` à sa façon : `EndLeave` rendait disponible un
 * agent parti, et une pause future l'empêchait de le redevenir. Tout flux qui touche aux
 * pauses ou au travail d'un agent appelle cette action, dans sa propre transaction.
 */
final class SyncDriverAvailability
{
    public function __invoke(Driver $driver): bool
    {
        $hasWork = $driver->activeDriverContract()->exists()
            || $driver->activeInternalAssignment()->exists();

        $aLeaveHasStarted = $driver->leaveRequests()
            ->where('status', 'ongoing')
            ->whereDate('start_date', '<=', now()->startOfDay())
            ->exists();

        $available = $hasWork && ! $aLeaveHasStarted;

        if ($driver->is_available !== $available) {
            $driver->update(['is_available' => $available]);
        }

        return $available;
    }
}

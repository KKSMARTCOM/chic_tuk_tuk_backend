<?php

namespace App\Domains\Booking\Application\Actions;

use App\Models\Driver;
use Illuminate\Support\Collection;

/**
 * Les agents à qui une course peut être confiée.
 *
 * ⚠️ CORRIGE un défaut du chemin Blade. La fenêtre d'affectation annonce « Sélectionnez
 * un Agent disponible » et appelle `/admin/drivers` avec `available=1` — un paramètre que
 * `Admin\DriverController::index()` ne lit pas : il ne retient que `search`, `is_active`
 * et `is_available`. Le menu listait donc TOUS les agents, y compris ceux en pause et
 * ceux dont le compte est désactivé, et l'affectation n'échouait qu'ensuite.
 *
 * Trois conditions, et pas une de plus :
 *
 *  1. le compte est ACTIF — un compte désactivé ne se connecte plus, la course
 *     resterait sans conducteur ;
 *  2. le profil est toujours `driver` — un compte peut changer de rôle ;
 *  3. l'agent est marqué DISPONIBLE — c'est ce drapeau que les pauses lèvent et
 *     baissent.
 *
 * ⚠️ On ne filtre PAS sur « a déjà une course en cours ». Un administrateur affecte
 * souvent à l'avance, pour la journée ou la semaine, et écarter ces agents-là viderait la
 * liste aux heures de pointe — exactement quand on en a besoin. Le nombre de courses en
 * cours est renvoyé à la place, pour que le choix se fasse en connaissance de cause.
 */
final class ListAssignableDrivers
{
    /** @return Collection<int, Driver> */
    public function __invoke(): Collection
    {
        return Driver::query()
            ->with('user')
            ->where('is_available', true)
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('profil', 'driver'))
            ->withCount([
                'bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', ['confirmed', 'in_progress']),
            ])
            ->get()
            // Le tri se fait en PHP sur le nom du COMPTE : il vit sur `users`, et trier en
            // SQL imposerait une jointure pour un gain nul sur une flotte de cette taille.
            ->sortBy(fn (Driver $driver) => $driver->user?->name ?? '', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}

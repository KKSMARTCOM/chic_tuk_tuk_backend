<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\AdminDriverListItemData;
use App\Models\Driver;
use App\Models\User;

/**
 * La liste générale des agents — ex-Admin\DriverController::index().
 *
 * ⚠️ Ne pas confondre avec `ListDriversForLeaves` : celle-ci ne garde que les agents
 * ayant eu un contrat, pour un dossier de pauses qui n'a de sens qu'avec un contrat de
 * référence. Ici, TOUS les comptes `profil=driver` sont listés, comme le Blade
 * `/admin/drivers` — un agent tout juste créé, sans aucun contrat ni véhicule, y figure.
 *
 * Filtre en plus le très improbable compte `profil=driver` sans ligne `drivers` : un tel
 * compte n'a pas d'identifiant d'agent, or les routes de ce domaine (comme celles des
 * pauses) sont keyées sur `drivers.id`.
 */
final class ListDrivers
{
    /**
     * @param  array{search?: ?string, is_active?: ?string, is_available?: ?string}  $filters
     * @return array{drivers: array<int, AdminDriverListItemData>, stats: array{total: int, active: int, inactive: int, available: int}}
     */
    public function __invoke(array $filters = []): array
    {
        $query = User::query()
            ->where('profil', 'driver')
            ->whereHas('driver')
            ->with('driver.currentVehicle');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('driver', function ($driverQuery) use ($search) {
                        $driverQuery->where('license_number', 'like', "%{$search}%")
                            ->orWhere('vehicle_number', 'like', "%{$search}%");
                    });
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (isset($filters['is_available']) && $filters['is_available'] !== '' && $filters['is_available'] !== null) {
            $query->whereHas('driver', fn ($q) => $q->where('is_available', (bool) $filters['is_available']));
        }

        $users = $query->latest()->get();

        return [
            'drivers' => $users->map(fn (User $u) => AdminDriverListItemData::fromModel($u))->all(),
            'stats' => $this->stats(),
        ];
    }

    private function stats(): array
    {
        $total = User::where('profil', 'driver')->count();
        $active = User::where('profil', 'driver')->where('is_active', true)->count();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            // Même requête que `DriverService::getDriverStats()` : TOUTES les lignes
            // `drivers` disponibles, sans repasser par `profil=driver` sur `users`.
            'available' => Driver::where('is_available', true)->count(),
        ];
    }
}

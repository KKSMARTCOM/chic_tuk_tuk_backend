<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminOwnerListItemData;
use App\Domains\Fleet\Application\Data\AdminOwnerPageData;
use App\Domains\Fleet\Application\Data\AdminOwnerStatsData;
use App\Models\User;

/**
 * La liste des propriétaires — ex-Admin\OwnerController::index().
 *
 * Même requête que le Blade (ex-`OwnerService`, déplacée ici le 2026-09-27) : un propriétaire est un compte
 * `profil=owner` qui porte AUSSI le rôle `proprietaire`.
 */
final class ListOwners
{
    /** @param  array{search?: ?string, is_active?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminOwnerPageData
    {
        $owners = $this->getAll($filters);
        $stats = $this->getStats();

        return new AdminOwnerPageData(
            owners: $owners->map(fn (User $owner) => AdminOwnerListItemData::fromModel($owner))->all(),
            stats: new AdminOwnerStatsData(
                total: $stats['total'],
                active: $stats['active'],
                inactive: $stats['inactive'],
            ),
        );
    }

    private function getAll(array $filters = [])
    {
        $query = User::with('roles', 'vehicles')
            ->where('profil', 'owner')
            ->role('proprietaire');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        if (isset($filters['profil']) && $filters['profil'] !== '') {
            $query->where('profil', $filters['profil']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->latest()->get();
    }

    private function getStats(): array
    {
        $users = User::where('profil', 'owner')->role('proprietaire');

        return [
            'total' => $users->count(),
            'active' => (clone $users)->where('is_active', true)->count(),
            'inactive' => (clone $users)->where('is_active', false)->count(),
        ];
    }
}

<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminOwnerListItemData;
use App\Domains\Fleet\Application\Data\AdminOwnerPageData;
use App\Domains\Fleet\Application\Data\AdminOwnerStatsData;
use App\Models\User;
use App\Services\OwnerService;

/**
 * La liste des propriétaires — ex-Admin\OwnerController::index().
 *
 * Même requête que le Blade, par `OwnerService` : un propriétaire est un compte
 * `profil=owner` qui porte AUSSI le rôle `proprietaire`.
 */
final class ListOwners
{
    public function __construct(private readonly OwnerService $ownerService) {}

    /** @param  array{search?: ?string, is_active?: ?string}  $filters */
    public function __invoke(array $filters = []): AdminOwnerPageData
    {
        $owners = $this->ownerService->getAll($filters);
        $stats = $this->ownerService->getStats();

        return new AdminOwnerPageData(
            owners: $owners->map(fn (User $owner) => AdminOwnerListItemData::fromModel($owner))->all(),
            stats: new AdminOwnerStatsData(
                total: $stats['total'],
                active: $stats['active'],
                inactive: $stats['inactive'],
            ),
        );
    }
}

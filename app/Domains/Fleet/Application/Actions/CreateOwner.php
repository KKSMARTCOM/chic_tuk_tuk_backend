<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\CreateOwnerData;
use App\Models\User;
use App\Services\OwnerService;

/**
 * Créer un propriétaire — ex-Admin\OwnerController::store().
 *
 * Le code est celui du Blade, dans `OwnerService::create()` : compte, véhicule et
 * contrat dans une même transaction. Les refus métier y sont des `ApiException`
 * (transfert non confirmé, véhicule sous contrat), qui traversent telles quelles.
 */
final class CreateOwner
{
    public function __construct(private readonly OwnerService $ownerService) {}

    public function __invoke(CreateOwnerData $data): User
    {
        return $this->ownerService->create($data->toServicePayload());
    }
}

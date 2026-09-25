<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateOwnerData;
use App\Models\User;
use App\Services\OwnerService;

/** Modifier un propriétaire et ses véhicules — ex-Admin\OwnerController::update(). */
final class UpdateOwner
{
    public function __construct(private readonly OwnerService $ownerService) {}

    public function __invoke(User $owner, UpdateOwnerData $data): User
    {
        return $this->ownerService->update($owner, $data->toServicePayload());
    }
}

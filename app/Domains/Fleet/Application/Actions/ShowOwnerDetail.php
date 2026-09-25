<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\AdminOwnerDetailData;

/** La fiche d'un propriétaire — ex-Admin\OwnerController::edit(). */
final class ShowOwnerDetail
{
    public function __construct(private readonly FindOwner $findOwner) {}

    public function __invoke(string $ownerId): AdminOwnerDetailData
    {
        $owner = ($this->findOwner)($ownerId);
        $owner->load('vehicles.activeVehicleContract', 'vehicles.activeDriverContract.driver.user');

        return AdminOwnerDetailData::fromModel($owner);
    }
}

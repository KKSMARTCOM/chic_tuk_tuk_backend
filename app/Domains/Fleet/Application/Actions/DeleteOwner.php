<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Models\User;
use App\Services\UserService;

/**
 * Supprimer un propriétaire — ex-Admin\UserController::destroy(), que la liste Blade
 * des propriétaires appelait.
 *
 * La règle vit dans `UserService::delete()`, partagée avec le Blade : refus
 * (`OWNER_NOT_DELETABLE`) dès qu'il a un véhicule ou un contrat véhicule.
 */
final class DeleteOwner
{
    public function __construct(private readonly UserService $userService) {}

    public function __invoke(User $owner): void
    {
        $this->userService->delete($owner);
    }
}

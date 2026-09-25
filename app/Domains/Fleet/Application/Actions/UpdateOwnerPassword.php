<?php

namespace App\Domains\Fleet\Application\Actions;

use App\Domains\Fleet\Application\Data\UpdateOwnerPasswordData;
use App\Models\User;
use App\Services\UserService;

/** Réinitialiser le mot de passe d'un propriétaire — ex-Admin\UserController::updatePassword(). */
final class UpdateOwnerPassword
{
    public function __construct(private readonly UserService $userService) {}

    public function __invoke(User $owner, UpdateOwnerPasswordData $data): void
    {
        $this->userService->updatePassword($owner, $data->password);
    }
}

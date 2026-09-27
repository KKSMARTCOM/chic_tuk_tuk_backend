<?php

namespace App\Domains\Workforce\Application\Actions;

use App\Domains\Workforce\Application\Data\UpdateDriverPasswordData;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Réinitialiser le mot de passe d'un agent — ex-Admin\DriverController::updatePassword(). */
final class UpdateDriverPassword
{
    public function __invoke(Driver $driver, UpdateDriverPasswordData $data): void
    {
        // `updateDriverPassword()` prend l'identifiant du COMPTE
        // (`users.id`), pas celui de l'agent — pont entre les deux conventions.
        $this->updateDriverPassword($driver->user_id, $data->password);
    }

    private function updateDriverPassword(string $driverId, string $password)
    {
        $user = User::findOrFail($driverId);
        $user->update(['password' => Hash::make($password)]);
    }
}

<?php

namespace Tests\Feature\Fleet;

use App\Consts\VehicleContractConsts;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les valeurs par défaut d'un contrat propriétaire-véhicule, servies au front.
 *
 * Elles vivaient recopiées dans le front ; `VehicleContractConsts` en est désormais la
 * seule source, pour le Blade comme pour l'API.
 */
class VehicleContractDefaultsApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function fetch(string $token)
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/vehicle-contracts/defaults');
    }

    public function test_the_defaults_come_from_vehicle_contract_consts(): void
    {
        $response = $this->fetch($this->login(['create-owners']))->assertOk();

        $expectedDurations = collect(VehicleContractConsts::TOTAL_AMOUNTS)
            ->map(fn ($amount, $months) => ['months' => $months, 'total_amount' => $amount])
            ->values()
            ->all();

        $this->assertEquals($expectedDurations, $response->json('durations'));
        $this->assertEquals(VehicleContractConsts::DEFAULT_UNLIMITED_INTERNET, $response->json('unlimited_internet'));
        $this->assertEquals(VehicleContractConsts::DEFAULT_SPOTIFY_PREMIUM, $response->json('spotify_premium'));
        $this->assertEquals(VehicleContractConsts::DEFAULT_MANAGER_REMUNERATION, $response->json('manager_remuneration'));
    }

    public function test_any_contract_writing_permission_opens_it(): void
    {
        // `manage-contracts` remplacée le 2026-09-26 par les permissions `*-contracts`.
        foreach (['create-owners', 'edit-owners', 'create-contracts', 'edit-contracts'] as $permission) {
            $this->fetch($this->login([$permission]))->assertOk();
        }
    }

    public function test_a_read_only_account_is_refused(): void
    {
        $this->fetch($this->login(['view-owners', 'view-contracts']))->assertForbidden();
    }
}

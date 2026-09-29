<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Models\VehicleContractChargeDefaults;
use App\Models\VehicleContractTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les valeurs par défaut d'un contrat propriétaire-véhicule, servies au front.
 *
 * Elles vivaient recopiées dans le front, puis dans des constantes ; depuis le 2026-09-29
 * elles viennent des réglages de l'administration.
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

    public function test_the_defaults_start_from_the_historical_amounts(): void
    {
        $response = $this->fetch($this->login(['create-owners']))->assertOk();

        $this->assertEquals([
            ['months' => 24, 'total_amount' => 3_100_000],
            ['months' => 30, 'total_amount' => 3_604_872],
            ['months' => 36, 'total_amount' => 4_049_100],
        ], $response->json('durations'));
        $this->assertEquals(5_000, $response->json('unlimited_internet'));
        $this->assertEquals(2_500, $response->json('spotify_premium'));
        $this->assertEquals(20_000, $response->json('manager_remuneration'));
    }

    public function test_the_defaults_follow_the_settings(): void
    {
        VehicleContractTerm::query()->where('months', 30)->delete();
        VehicleContractTerm::create(['months' => 48, 'total_amount' => 5_000_000, 'daily_amount' => 5000, 'daily_tax' => 200]);
        VehicleContractChargeDefaults::query()->update(['spotify_premium' => 3_000]);

        $response = $this->fetch($this->login(['create-owners']))->assertOk();

        $this->assertEquals([24, 36, 48], collect($response->json('durations'))->pluck('months')->all());
        $this->assertEquals(3_000, $response->json('spotify_premium'));
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

<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OwnerVehiclesIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Connecte un utilisateur par l'API et renvoie [$user, $token]. */
    private function login(Profil $profil, array $permissions = []): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_sans_jeton_la_reponse_est_un_401_json(): void
    {
        // Et surtout PAS une 302 vers /login, que l'ancienne branche Blade de
        // CheckPermission renvoyait (retirée le 2026-09-27).
        $this->getJson('/api/v1/owner/vehicles')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_un_jeton_d_un_autre_profil_est_refuse(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-own-vehicles']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_le_bon_profil_sans_la_permission_est_refuse(): void
    {
        [, $token] = $this->login(Profil::Owner);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertStatus(403);
    }

    public function test_ne_renvoie_que_les_vehicules_de_l_appelant(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $mien = Vehicle::factory()->create(['owner_id' => $owner->id]);
        Vehicle::factory()->create(); // celui d'un autre propriétaire

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mien->id)
            ->assertJsonPath('0.vehicle_number', $mien->vehicle_number);
    }

    public function test_un_vehicule_sans_contrat_actif_renvoie_un_contrat_nul(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->completed()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonPath('0.contract', null)
            ->assertJsonPath('0.is_on_pause', false);
    }

    public function test_expose_l_avancement_du_contrat_actif(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonPath('0.contract.contract_months', 24)
            ->assertJsonStructure([
                ['id', 'vehicle_number', 'vehicle_type', 'is_on_pause', 'contract' => [
                    'contract_months', 'months_elapsed', 'months_remaining',
                    'progress_percentage', 'remaining_amount',
                ]],
            ]);
    }

    public function test_une_liste_vide_est_un_tableau_vide_et_non_une_erreur(): void
    {
        [, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertExactJson([]);
    }
}

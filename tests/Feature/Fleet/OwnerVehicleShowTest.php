<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OwnerVehicleShowTest extends TestCase
{
    use RefreshDatabase;

    private function loginOwner(): array
    {
        $user = User::factory()->profil(Profil::Owner)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        Permission::findOrCreate('view-own-contracts', 'web');
        $user->givePermissionTo('view-own-contracts');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_le_vehicule_d_un_autre_proprietaire_renvoie_404(): void
    {
        [$owner, $token] = $this->loginOwner();
        $mien = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $autre = Vehicle::factory()->create();

        // ⚠️ La 200 sur MON véhicule est ce qui rend ce test discriminant. Sans elle,
        // il passerait alors même que la route n'existe pas : une route absente répond
        // 404 tout comme un refus de portée, et le test ne prouverait rien.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$mien->id}")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$autre->id}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_un_identifiant_inconnu_renvoie_404(): void
    {
        [$owner, $token] = $this->loginOwner();
        $mien = Vehicle::factory()->create(['owner_id' => $owner->id]);

        // Même raison que ci-dessus : la 200 d'abord, sinon l'absence de route suffit
        // à faire passer le test.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$mien->id}")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles/'.Str::uuid())
            ->assertStatus(404);
    }

    public function test_expose_le_contrat_complet_et_ses_charges(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('id', $vehicle->id)
            ->assertJsonPath('active_pause', null)
            ->assertJsonPath('contract.contract_months', 24)
            ->assertJsonPath('contract.unlimited_internet', 5000)
            ->assertJsonPath('contract.spotify_premium', 2500)
            ->assertJsonPath('contract.manager_remuneration', 20000)
            ->assertJsonPath('contract.total_charges', 27500)
            ->assertJsonStructure(['contract' => [
                'start_date', 'total_days', 'invested_amount',
                'total_amount', 'total_paid', 'remaining_amount', 'daily_net_amount',
                'progress_percentage', 'months_elapsed', 'months_remaining',
                'pauses' => ['pause_allowance', 'pause_days_taken', 'pause_days_available', 'pause_days_remaining', 'pause_overrun'],
            ]]);
    }

    public function test_the_contract_no_longer_shows_an_end_date_and_counts_worked_months(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 24, 'start_date' => '2026-05-01',
        ]);
        // Mai entièrement immobilisé : non travaillé. Juin et juillet travaillés.
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'reason_type' => 'agent_change',
        ]);
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'reason_type' => 'agent_leave',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonMissingPath('contract.planned_end_date')
            ->assertJsonMissingPath('contract.extended_end_date')
            ->assertJsonPath('contract.months_elapsed', 2)
            ->assertJsonPath('contract.months_remaining', 22)
            ->assertJsonPath('contract.total_days', 528)
            ->assertJsonPath('contract.invested_amount', 1_799_500)
            ->assertJsonPath('contract.pauses.pause_allowance', 48)
            ->assertJsonPath('contract.pauses.pause_days_taken', 2)
            ->assertJsonPath('contract.pauses.pause_days_available', 2)
            ->assertJsonPath('contract.pauses.pause_days_remaining', 46)
            ->assertJsonPath('contract.pauses.pause_overrun', 0);

        Carbon::setTestNow();
    }

    public function test_les_dates_sortent_au_format_iso(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create([
            'start_date' => '2026-01-15',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            // Ni « 15 janv. 2026 » ni un ISO 8601 complet avec heure : le front
            // formate lui-même, et une date sans heure n'a pas de fuseau.
            ->assertJsonPath('contract.start_date', '2026-01-15');
    }

    public function test_expose_la_pause_en_cours_avec_son_libelle_francais(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();
        VehiclePause::factory()->forContract($contract)->ongoing()->create([
            'reason_type' => 'technical',
            'start_date' => '2026-09-01',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('active_pause.start_date', '2026-09-01')
            ->assertJsonPath('active_pause.reason_type', 'technical')
            // L'écran Blade affichait « Technical » ; le modèle sait dire mieux.
            ->assertJsonPath('active_pause.reason_label', 'Problème technique');
    }

    public function test_un_vehicule_sans_contrat_actif_repond_200_avec_contrat_nul(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('contract', null);
    }

    public function test_the_overview_carries_the_latest_validated_sheet_and_nothing_without_one(): void
    {
        // Les cumuls « réalisés » de l'aperçu viennent de la dernière fiche validée, figée :
        // le front ne recalcule jamais un cumul.
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('contract.latest_statement', null);

        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-09-01', 'figures' => ['month' => '2026-09', 'cumulative_revenue' => 50_000, 'cumulative_net' => 30_000],
        ]);
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-10-01', 'figures' => ['month' => '2026-10', 'cumulative_revenue' => 92_854, 'cumulative_net' => 65_354],
        ]);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-11-01']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}")
            ->assertOk()
            ->assertJsonPath('contract.latest_statement.month', '2026-10')
            ->assertJsonPath('contract.latest_statement.cumulative_revenue', 92_854)
            ->assertJsonPath('contract.latest_statement.cumulative_net', 65_354);
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MonthlyPayoutRecapTest extends TestCase
{
    use RefreshDatabase;

    private function loginOwner(): array
    {
        $user = User::factory()->profil(Profil::Owner)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        Permission::findOrCreate('view-own-payments', 'web');
        $user->givePermissionTo('view-own-payments');

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

        // ⚠️ La 200 sur MON véhicule est ce qui rend ce test discriminant : une route
        // absente répond 404 tout comme un refus de portée.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$mien->id}/payments")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$autre->id}/payments")
            ->assertStatus(404);
    }

    public function test_sans_contrat_actif_la_liste_est_vide(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_le_mois_courant_figure_meme_sans_aucun_paiement(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk();

        $this->assertSame([now()->format('Y-m')], array_column($response->json(), 'month'));
        $this->assertTrue($response->json('0.is_current'));
    }

    public function test_les_mois_sortent_du_plus_recent_au_plus_ancien(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'start_date' => now()->subMonths(3)->startOfMonth(),
        ]);

        $ilYaDeuxMois = now()->subMonths(2)->startOfMonth();
        $ilYaUnMois = now()->subMonth()->startOfMonth();

        Payment::factory()->onDay($ilYaDeuxMois->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);
        Payment::factory()->onDay($ilYaUnMois->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);

        $mois = array_column(
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
                ->assertOk()->json(),
            'month',
        );

        $this->assertSame([
            now()->format('Y-m'),
            $ilYaUnMois->format('Y-m'),
            $ilYaDeuxMois->format('Y-m'),
        ], $mois);
    }

    public function test_un_mois_futur_est_exclu(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        // Un paiement daté d'un mois à venir ne doit pas créer une ligne de
        // récapitulatif : le Blade les filtre, et une ligne future afficherait des
        // jours travaillés sans signification.
        Payment::factory()->onDay(now()->addMonth()->startOfMonth()->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);

        $mois = array_column(
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
                ->assertOk()->json(),
            'month',
        );

        $this->assertNotContains(now()->addMonth()->format('Y-m'), $mois);
    }

    public function test_separe_les_montants_par_statut_et_deduit_les_charges(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        $jour = now()->startOfMonth()->toDateString();
        Payment::factory()->onDay($jour)->status('completed')
            ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 100000]);
        Payment::factory()->onDay($jour)->status('pending')
            ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 20000]);
        Payment::factory()->onDay($jour)->status('cancelled')
            ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5000]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertJsonPath('0.validated_amount', 100000)
            ->assertJsonPath('0.pending_amount', 20000)
            ->assertJsonPath('0.cancelled_amount', 5000)
            ->assertJsonPath('0.total_charges', 27500)
            // fixed_amount = validé − charges, quand rien n'est reporté.
            ->assertJsonPath('0.fixed_amount', 72500)
            ->assertJsonPath('0.deficit_carried_in', 0)
            ->assertJsonPath('0.deficit_carried_out', 0);
    }

    /** Le récapitulatif d'un contrat, avec un paiement validé de `net` par mois donné. */
    private function recapWithValidated(array $netByMonthsAgo): array
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'start_date' => now()->subMonths(4)->startOfMonth(),
        ]);

        foreach ($netByMonthsAgo as $monthsAgo => $net) {
            Payment::factory()->onDay(now()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->toDateString())
                ->status('completed')
                ->create(['vehicle_contract_id' => $contract->id, 'net_amount' => $net]);
        }

        $rows = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()->json();

        return collect($rows)->keyBy('month')->all();
    }

    private function monthKey(int $monthsAgo): string
    {
        return now()->subMonthsNoOverflow($monthsAgo)->format('Y-m');
    }

    public function test_un_mois_deficitaire_s_affiche_a_zero_et_reporte_son_manque(): void
    {
        // Décidé le 2026-09-29 : le propriétaire ne voit plus de montant négatif. Le
        // manque du mois est déduit du mois suivant, jusqu'à être couvert.
        $recap = $this->recapWithValidated([2 => 10_000, 1 => 100_000]);

        // Il y a deux mois : 10 000 validés, 27 500 de charges.
        $this->assertEquals(0, $recap[$this->monthKey(2)]['fixed_amount']);
        $this->assertEquals(17_500, $recap[$this->monthKey(2)]['deficit_carried_out']);

        // Le mois suivant rembourse ce manque avant de dégager un montant fixe.
        $this->assertEquals(17_500, $recap[$this->monthKey(1)]['deficit_carried_in']);
        $this->assertEquals(100_000 - 27_500 - 17_500, $recap[$this->monthKey(1)]['fixed_amount']);
        $this->assertEquals(0, $recap[$this->monthKey(1)]['deficit_carried_out']);
    }

    public function test_un_deficit_s_accumule_tant_qu_il_n_est_pas_couvert(): void
    {
        $recap = $this->recapWithValidated([3 => 20_000, 2 => 10_000, 1 => 100_000]);

        $this->assertEquals(7_500, $recap[$this->monthKey(3)]['deficit_carried_out']);
        // 10 000 − 27 500 − 7 500 reporté.
        $this->assertEquals(7_500, $recap[$this->monthKey(2)]['deficit_carried_in']);
        $this->assertEquals(25_000, $recap[$this->monthKey(2)]['deficit_carried_out']);
        $this->assertEquals(0, $recap[$this->monthKey(2)]['fixed_amount']);
        $this->assertEquals(100_000 - 27_500 - 25_000, $recap[$this->monthKey(1)]['fixed_amount']);
    }

    public function test_aucun_montant_fixe_n_est_jamais_negatif(): void
    {
        $recap = $this->recapWithValidated([1 => 0]);

        foreach ($recap as $month) {
            $this->assertGreaterThanOrEqual(0, $month['fixed_amount']);
        }
        // Le mois courant, sans paiement, hérite du manque du précédent.
        $this->assertEquals(27_500, $recap[$this->monthKey(0)]['deficit_carried_in']);
        $this->assertEquals(55_000, $recap[$this->monthKey(0)]['deficit_carried_out']);
    }

    public function test_les_jours_travailles_comptent_les_dates_distinctes(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        $premier = now()->startOfMonth();
        // Deux paiements le même jour ne comptent qu'un jour travaillé.
        Payment::factory()->onDay($premier->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);
        Payment::factory()->onDay($premier->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);
        Payment::factory()->onDay($premier->copy()->addDay()->toDateString())
            ->create(['vehicle_contract_id' => $contract->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertJsonPath('0.worked_days', 2);
    }

    public function test_une_immobilisation_a_cheval_n_est_comptee_que_pour_sa_part_du_mois(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'start_date' => '2026-01-01',
        ]);

        // Du 28 février au 3 mars 2026 : la part de mars va du 1er au 3.
        // Le 1er mars 2026 est un dimanche, le 2 un lundi, le 3 un mardi : deux jours
        // ouvrés, pas trois. Le comptage exclut les week-ends.
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-02-28', 'end_date' => '2026-03-03',
        ]);
        Payment::factory()->onDay('2026-03-10')
            ->create(['vehicle_contract_id' => $contract->id]);

        $mars = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()->json())
            ->firstWhere('month', '2026-03');

        $this->assertSame(2, $mars['immobilization_days']);
    }

    public function test_un_conge_d_agent_a_cheval_est_compte_comme_tel(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'start_date' => '2026-01-01',
        ]);
        $driverContract = DriverContract::factory()->forVehicleContract($contract)->create();

        LeaveRequest::factory()->create([
            'driver_contract_id' => $driverContract->id,
            'start_date' => '2026-02-28',
            'end_date' => '2026-03-03',
            'status' => 'completed',
        ]);
        Payment::factory()->onDay('2026-03-10')
            ->create(['vehicle_contract_id' => $contract->id]);

        $mars = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()->json())
            ->firstWhere('month', '2026-03');

        // Mêmes bornes que l'immobilisation ci-dessus, même comptage en jours ouvrés,
        // mais une ligne distincte : congé d'agent et immobilisation ne se confondent
        // pas, malgré la route Blade qui les appelait toutes deux « leaves ».
        $this->assertSame(2, $mars['agent_leave_days']);
        $this->assertSame(0, $mars['immobilization_days']);
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MonthlyPayoutRecapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Un mercredi en milieu de mois : le mois courant est travaillé. Figée parce que
        // les mois non travaillés rendent le résultat dépendant du jour du test.
        Carbon::setTestNow('2026-07-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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
        VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => now()->startOfMonth()]);

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
            'start_date' => now()->subMonths(2)->startOfMonth(),
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

    public function test_the_current_month_counts_only_paid_days(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-07-01']);
        // Un seul paiement : le calendrier compte 11 jours ouvrés du 1er au 15, mais
        // l'estimation du mois en cours ne compte que les jours payés (spec 2026-10-22, §4.3).
        Payment::factory()->onDay('2026-07-01')->create(['vehicle_contract_id' => $contract->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertJsonPath('0.business_days', 11)
            ->assertJsonPath('0.counted_days', 1)
            ->assertJsonMissingPath('0.worked_days');
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

    public function test_an_agent_pause_straddling_months_is_counted_as_a_pause(): void
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
        // mais une ligne distincte : pause d'agent et immobilisation ne se confondent
        // pas, malgré la route Blade qui les appelait toutes deux « leaves ». Pause
        // saisie sans pause véhicule : c'est le cas d'une pause historique (spec §3.2).
        $this->assertSame(2, $mars['agent_leave_days']);
        $this->assertSame(0, $mars['immobilization_days']);
    }

    public function test_an_agent_pause_is_not_also_counted_as_immobilization(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-07-01']);
        $driverContract = DriverContract::factory()->forVehicleContract($contract)->create();
        // La pause d'agent ET sa pause véhicule automatique, sur les mêmes jours : c'est
        // ce que la base contient d'ordinaire, et ce que l'ancien calcul comptait deux fois.
        LeaveRequest::factory()->create([
            'driver_contract_id' => $driverContract->id,
            'start_date' => '2026-07-06', 'end_date' => '2026-07-07', 'status' => 'completed',
        ]);
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-07-06', 'end_date' => '2026-07-07',
            'reason_type' => 'agent_leave', 'is_auto' => true,
            'driver_contract_id' => $driverContract->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertJsonPath('0.agent_leave_days', 2)
            ->assertJsonPath('0.immobilization_days', 0);
    }

    public function test_a_month_without_counted_day_has_no_charge(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-06-01']);
        // Juin entier immobilisé : mois non travaillé.
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'reason_type' => 'agent_change',
        ]);

        $june = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()->json())->firstWhere('month', '2026-06');

        $this->assertFalse($june['is_worked']);
        // Avant la mise en service des fiches : aucune charge ni aucun solde annoncé.
        $this->assertNull($june['charges_deducted']);
        $this->assertNull($june['balance_due']);
    }

    /** Les tests des fiches se placent le 2026-11-15, fiches en service depuis octobre. */
    private function atNovember(): void
    {
        Carbon::setTestNow('2026-11-15 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
    }

    public function test_a_validated_month_shows_its_frozen_sheet(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-10-01', 'number' => 'FR-2026-10-001', 'pdf_path' => 'statements/2026/FR-2026-10-001.pdf',
            'balance_due' => 31_210,
            'figures' => ['revenue' => 58_710, 'recovered' => 0, 'deducted_total' => 27_500, 'balance_due' => 31_210],
        ]);
        // Un paiement validé APRÈS la fiche : il ne change pas le mois figé.
        Payment::factory()->onDay('2026-10-30')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);

        $october = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json())
            ->firstWhere('month', '2026-10');

        $this->assertSame('validated', $october['status']);
        $this->assertSame('FR-2026-10-001', $october['statement_number']);
        $this->assertEquals(58_710, $october['revenue']);
        $this->assertEquals(31_210, $october['balance_due']);
        $this->assertTrue($october['has_pdf']);
    }

    public function test_a_closed_month_awaiting_review_shows_no_balance(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);

        $october = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json())
            ->firstWhere('month', '2026-10');

        $this->assertSame('review_pending', $october['status']);
        $this->assertNull($october['balance_due']);
        $this->assertGreaterThan(0, $october['counted_days']);
    }

    public function test_the_current_month_is_an_estimate(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        // Un seul jour comptabilisé, payé 100 000 : le reste de novembre immobilisé.
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-11-01', 'daily_amount' => 100_000, 'daily_tax' => 0]);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-11-03', 'end_date' => '2026-11-30', 'reason_type' => 'agent_change']);
        Payment::factory()->onDay('2026-11-02')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 100_000]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")
            ->assertOk()
            ->assertJsonPath('0.status', 'current')
            ->assertJsonPath('0.is_estimate', true)
            ->assertJsonPath('0.balance_due', 72_500);
    }

    public function test_the_current_month_estimate_never_exceeds_the_money_received(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-11-02']);
        // Du 2 au 13 novembre, 10 jours ouvrés : 9 payés, le 13 pas encore généré.
        foreach (collect(Carbon::parse('2026-11-02')->toPeriod('2026-11-12'))->reject->isWeekend() as $day) {
            Payment::factory()->onDay($day->toDateString())->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);
        }

        $november = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json('0');

        $this->assertSame('current', $november['status']);
        $this->assertSame(9, $november['counted_days']);
        $this->assertEquals(9 * 5_871, $november['total_revenue']);
    }

    public function test_a_validated_month_shows_the_new_formula(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-10-01',
            'figures' => ['counted_days' => 21, 'recovered_count' => 2, 'total_revenue' => 123_291, 'balance_due' => 95_791],
        ]);

        $october = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json())
            ->firstWhere('month', '2026-10');

        $this->assertSame(21, $october['counted_days']);
        $this->assertSame(2, $october['recovered_count']);
        $this->assertEquals(123_291, $october['total_revenue']);
    }

    public function test_old_validated_months_are_recomposed_with_one_query(): void
    {
        Carbon::setTestNow('2027-01-15 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);
        foreach (['2026-10', '2026-11', '2026-12'] as $month) {
            RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
                'month' => $month.'-01',
                'figures' => ['counted_days' => 20, 'pending_count' => 0, 'daily_amount' => 5_000, 'revenue' => 100_000, 'recovered' => 0],
            ]);
        }
        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            // Les recouvrés des fiches : le compte groupé, ou un compte par fiche s'il manquait.
            if (str_contains($query->sql, 'from "payments"') && str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'remuneration_statement_id')) {
                $queries++;
            }
        });

        $months = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json());

        $this->assertSame(1, $queries);
        $this->assertEquals(100_000, $months->firstWhere('month', '2026-11')['total_revenue']);
        $this->assertSame(0, $months->firstWhere('month', '2026-11')['recovered_count']);
    }

    public function test_a_partial_payment_never_lifts_the_estimate_above_the_money_received(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-11-02']);
        foreach (collect(Carbon::parse('2026-11-02')->toPeriod('2026-11-12'))->reject->isWeekend() as $i => $day) {
            Payment::factory()->onDay($day->toDateString())->create(['vehicle_contract_id' => $contract->id, 'net_amount' => $i === 0 ? 3_000 : 5_871]);
        }

        $november = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json('0');

        $this->assertEquals(3_000 + 8 * 5_871, $november['total_revenue']);
    }

    public function test_a_legacy_validated_month_keeps_the_old_card(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);
        // Validée avant la formule, avec un écart : 20 jours à 5 000 n'ont rapporté que 90 000.
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-10-01',
            'figures' => ['counted_days' => 20, 'pending_count' => 0, 'daily_amount' => 5_000, 'revenue' => 90_000, 'recovered' => 0],
        ]);

        $october = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json())
            ->firstWhere('month', '2026-10');

        $this->assertNull($october['total_revenue']);
        $this->assertEquals(90_000, $october['revenue']);
    }

    public function test_a_month_awaiting_review_counts_pending_only_on_calendar_days(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-10-01']);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'reason_type' => 'agent_leave']);
        Payment::factory()->onDay('2026-10-01')->status('pending')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);
        Payment::factory()->onDay('2026-10-02')->status('pending')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);

        $october = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json())
            ->firstWhere('month', '2026-10');

        $this->assertSame('review_pending', $october['status']);
        $this->assertSame(1, $october['pending_count']);
        $this->assertEquals(5_871, $october['pending_amount']);
    }

    public function test_the_current_month_shows_its_unpaid_days(): void
    {
        $this->atNovember();
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-11-02']);
        // Du 2 au 13 novembre, 10 jours ouvrés : 9 payés.
        foreach (collect(Carbon::parse('2026-11-02')->toPeriod('2026-11-12'))->reject->isWeekend() as $day) {
            Payment::factory()->onDay($day->toDateString())->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);
        }

        $months = collect($this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/payments")->assertOk()->json());

        $this->assertSame(1, $months->firstWhere('month', '2026-11')['unpaid_days']);
    }
}

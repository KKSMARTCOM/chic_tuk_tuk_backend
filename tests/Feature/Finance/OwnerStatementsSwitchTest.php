<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * L'interrupteur des fiches côté propriétaire (spec 2026-10-02, §3.2) : éteint en
 * production le temps de la reconstitution, le propriétaire ne voit aucune fiche — ni
 * liste, ni PDF, ni mois figé, ni cumul tiré d'une fiche.
 */
class OwnerStatementsSwitchTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Vehicle $vehicle;

    private VehicleContract $contract;

    private RemunerationStatement $statement;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        Storage::fake('local');

        $owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-own-payments', 'view-own-vehicles', 'view-own-contracts'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $owner->givePermissionTo($permission);
        }
        $this->token = $this->postJson('/api/v1/auth/login', [
            'email' => $owner->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        $this->vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $this->contract = VehicleContract::factory()->forVehicle($this->vehicle)->create(['start_date' => '2026-09-01']);
        $this->statement = RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create([
            'month' => '2026-10-01', 'number' => 'FR-2026-10-001', 'balance_due' => 31_210,
            'pdf_path' => 'statements/2026/FR-2026-10-001.pdf',
            'figures' => ['month' => '2026-10', 'revenue' => 58_710, 'recovered' => 0, 'deducted_total' => 27_500,
                'balance_due' => 31_210, 'cumulative_revenue' => 58_710, 'cumulative_net' => 31_210],
        ]);
        Storage::disk('local')->put('statements/2026/FR-2026-10-001.pdf', '%PDF-1.7 fiche');
        Payment::factory()->onDay('2026-09-10')->create(['vehicle_contract_id' => $this->contract->id, 'net_amount' => 5_871]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fetch(string $uri)
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}")->getJson($uri);
    }

    private function off(): void
    {
        config(['remuneration.owner_visible' => false]);
    }

    public function test_switched_on_nothing_changes(): void
    {
        $this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}/statements")->assertOk()->assertJsonCount(1);
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->get("/api/v1/owner/statements/{$this->statement->id}/pdf")->assertOk();

        $october = collect($this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}/payments")->json())->firstWhere('month', '2026-10');
        $this->assertSame('validated', $october['status']);
        $this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}")->assertJsonPath('contract.latest_statement.month', '2026-10');
        $this->assertEquals(27_500, collect($this->fetch('/api/v1/owner/vehicles')->json())->first()['contract']['charges_deducted']);
    }

    public function test_switched_off_the_list_and_the_pdf_are_not_found(): void
    {
        $this->off();

        $this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}/statements")->assertNotFound();
        // Un ancien lien gardé par le propriétaire : 404, jamais le fichier ni une 500.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->get("/api/v1/owner/statements/{$this->statement->id}/pdf")->assertNotFound();
        $this->assertSame(0, $this->statement->refresh()->owner_download_count);
    }

    public function test_switched_off_closed_months_show_receipts_without_balance(): void
    {
        $this->off();

        $months = collect($this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}/payments")->assertOk()->json())->keyBy('month');

        // Octobre a une fiche validée : elle est ignorée, le mois montre ses recettes validées.
        $this->assertSame('before_statements', $months['2026-10']['status']);
        $this->assertNull($months['2026-10']['balance_due']);
        $this->assertNull($months['2026-10']['statement_id']);
        // Septembre précède `first_month` : inchangé.
        $this->assertSame('before_statements', $months['2026-09']['status']);
        $this->assertEquals(5_871, $months['2026-09']['revenue']);
        // Le mois en cours garde son estimation.
        $this->assertSame('current', $months['2026-11']['status']);
        $this->assertTrue($months['2026-11']['is_estimate']);
    }

    /**
     * Le mois en cours, interrupteur éteint : ses jours et ses recettes validées, mais ni
     * solde ni recouvré ni charges — ils dépendent des fiches antérieures, que la
     * reconstitution valide une à une (décidé le 2026-10-02 après relecture).
     */
    public function test_switched_off_the_current_month_shows_no_balance(): void
    {
        Payment::factory()->onDay('2026-11-03')->create(['vehicle_contract_id' => $this->contract->id, 'net_amount' => 6_000]);
        $this->off();

        $november = collect($this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}/payments")->assertOk()->json())->firstWhere('month', '2026-11');

        $this->assertSame('current', $november['status']);
        $this->assertTrue($november['is_estimate']);
        $this->assertEquals(6_000, $november['revenue']);
        $this->assertNull($november['balance_due']);
        $this->assertNull($november['recovered']);
        $this->assertNull($november['charges_deducted']);
    }

    public function test_switched_off_the_overview_carries_no_sheet_figures(): void
    {
        $this->off();

        $this->fetch("/api/v1/owner/vehicles/{$this->vehicle->id}")->assertOk()->assertJsonPath('contract.latest_statement', null);
        $this->assertNull(collect($this->fetch('/api/v1/owner/vehicles')->assertOk()->json())->first()['contract']['charges_deducted']);
    }

    /** Une valeur d'environnement écrite `0` éteint aussi. */
    public function test_a_zero_string_switches_off(): void
    {
        config(['remuneration.owner_visible' => '0']);

        $this->assertFalse(RemunerationStatement::visibleToOwners());
    }
}

<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\OwnerDisbursement;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le dossier d'un propriétaire (spec 2026-11-04-dossier-proprietaire) : tout ce qui le
 * concerne en un appel, chaque bloc financier sous sa permission.
 */
class AdminOwnerDossierApiTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = ['view-owners', 'view-remuneration-statements', 'view-disbursements', 'view-payments', 'view-activity-log'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(array $permissions = self::ALL): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function owner(array $attributes = []): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create($attributes);
        $owner->assignRole(Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']));

        return $owner;
    }

    private function vehicle(User $owner, string $number, array $attributes = []): Vehicle
    {
        return Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => $number] + $attributes);
    }

    private function contract(Vehicle $vehicle, array $attributes = []): VehicleContract
    {
        return VehicleContract::factory()->forVehicle($vehicle)->create($attributes);
    }

    private function dossier(User $owner, array $permissions = self::ALL)
    {
        return $this->api($permissions)->getJson("/api/v1/admin/owners/{$owner->id}/dossier");
    }

    private function vehicleRow(array $json, string $number): array
    {
        return collect($json['vehicles'])->firstWhere('vehicle_number', $number);
    }

    public function test_the_dossier_gathers_vehicles_contracts_and_totals(): void
    {
        $owner = $this->owner(['name' => 'Koffi AGBODJAN']);

        $running = $this->contract($this->vehicle($owner, 'A-0001'), ['total_amount' => 1_000_000, 'contract_months' => 24]);
        $agent = DriverContract::factory()->forVehicleContract($running)->create();
        Payment::factory()->onDay('2026-11-02')->create(['vehicle_contract_id' => $running->id, 'driver_id' => $agent->driver_id, 'net_amount' => 100_000]);

        $immobilized = $this->contract($this->vehicle($owner, 'A-0002'), ['total_amount' => 500_000]);
        VehiclePause::factory()->forContract($immobilized)->ongoing()->create(['start_date' => '2026-10-05', 'reason_type' => 'agent_change']);

        $this->contract($this->vehicle($owner, 'A-0003'), ['status' => 'pending', 'start_date' => null, 'total_amount' => 300_000]);
        $this->vehicle($owner, 'A-0004');

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertSame('Koffi AGBODJAN', $json['name']);
        $this->assertSame(['A-0001', 'A-0002', 'A-0003', 'A-0004'], array_column($json['vehicles'], 'vehicle_number'));

        $first = $this->vehicleRow($json, 'A-0001');
        $this->assertSame('active', $first['state']);
        $this->assertSame($agent->driver_id, $first['driver']['id']);
        $this->assertSame($running->id, $first['contract']['id']);
        $this->assertSame(48, $first['contract']['pauses']['pause_allowance']);
        // La fin prévue du contrat en cours (relecture du 2026-11-04) : début + durée.
        $this->assertSame($running->planned_end_date->toDateString(), $first['contract']['planned_end_date']);
        $this->assertEquals(100_000, $first['contract']['total_paid']);

        $second = $this->vehicleRow($json, 'A-0002');
        $this->assertSame('immobilized', $second['state']);
        $this->assertSame('2026-10-05', $second['pause_since']);

        $this->assertSame('pending', $this->vehicleRow($json, 'A-0003')['state']);
        $fourth = $this->vehicleRow($json, 'A-0004');
        $this->assertSame('no_contract', $fourth['state']);
        $this->assertNull($fourth['contract']);

        $this->assertSame(4, $json['totals']['vehicles_total']);
        $this->assertSame(1, $json['totals']['vehicles_in_service']);
        // (1 000 000 − 100 000) + 500 000 + 300 000
        $this->assertEquals(1_700_000, $json['totals']['remaining']);
    }

    public function test_an_inactive_vehicle_is_inactive_whatever_its_contract(): void
    {
        $owner = $this->owner();
        $this->contract($this->vehicle($owner, 'A-0001', ['is_active' => false]));

        $this->assertSame('inactive', $this->vehicleRow($this->dossier($owner)->json(), 'A-0001')['state']);
    }

    public function test_outstanding_and_disbursed_follow_the_disbursement_summary(): void
    {
        $owner = $this->owner();
        $contract = $this->contract($this->vehicle($owner, 'A-0001'));
        RemunerationStatement::factory()->validated()->create(['vehicle_contract_id' => $contract->id, 'month' => '2026-09-01', 'balance_due' => 100_000]);
        RemunerationStatement::factory()->validated()->create(['vehicle_contract_id' => $contract->id, 'month' => '2026-10-01', 'balance_due' => 50_000]);
        OwnerDisbursement::create(['vehicle_contract_id' => $contract->id, 'paid_on' => '2026-10-06', 'amount' => 80_000, 'method' => 'mobile_money', 'status' => 'recorded']);
        OwnerDisbursement::create(['vehicle_contract_id' => $contract->id, 'paid_on' => '2026-10-07', 'amount' => 30_000, 'method' => 'mobile_money', 'status' => 'cancelled']);

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertEquals(80_000, $json['totals']['disbursed']);
        $this->assertEquals(70_000, $json['totals']['outstanding']);
        $this->assertCount(2, $json['disbursements']);
        $this->assertSame('A-0001', $json['disbursements'][0]['vehicle_number']);
        $this->assertSame($contract->id, $json['disbursements'][0]['vehicle_contract_id']);
        $this->assertSame('2026-10-07', $json['disbursements'][0]['disbursement']['paid_on']);
    }

    public function test_a_settled_vehicle_hides_agency_payments(): void
    {
        $owner = $this->owner();
        $settled = $this->contract($this->vehicle($owner, 'A-0001'), ['status' => 'completed', 'start_date' => '2026-01-01', 'end_date' => '2026-08-31']);
        $mine = Payment::factory()->onDay('2026-08-30')->create(['vehicle_contract_id' => $settled->id]);
        $agency = Payment::factory()->onDay('2026-09-02')->create(['vehicle_contract_id' => $settled->id]);

        $json = $this->dossier($owner)->assertOk()->json();

        $row = $this->vehicleRow($json, 'A-0001');
        $this->assertSame('completed', $row['state']);
        $this->assertSame($settled->id, $row['contract']['id']);
        $ids = array_column($json['payments'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($agency->id, $ids);
    }

    public function test_past_contracts_of_the_vehicle_are_listed(): void
    {
        $owner = $this->owner();
        $vehicle = $this->vehicle($owner, 'A-0001');
        $old = $this->contract($vehicle, ['status' => 'completed', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $current = $this->contract($vehicle);

        $row = $this->vehicleRow($this->dossier($owner)->json(), 'A-0001');

        $this->assertSame($current->id, $row['contract']['id']);
        $this->assertSame([['id' => $old->id, 'status' => 'completed', 'end_date' => '2025-12-31']], $row['past_contracts']);
    }

    public function test_each_block_needs_its_permission(): void
    {
        $owner = $this->owner();
        $this->contract($this->vehicle($owner, 'A-0001'));

        $limited = $this->dossier($owner, ['view-owners'])->assertOk()->json();
        foreach (['statements', 'disbursements', 'payments', 'activity'] as $block) {
            $this->assertNull($limited[$block], $block);
        }
        $this->assertCount(1, $limited['vehicles']);
        // Ce que l'agence a versé et doit au propriétaire : sous view-disbursements (2026-11-04).
        $this->assertNull($limited['totals']['disbursed']);
        $this->assertNull($limited['totals']['outstanding']);
        $this->assertNotNull($limited['totals']['remaining']);

        $full = $this->dossier($owner)->assertOk()->json();
        foreach (['statements', 'disbursements', 'payments', 'activity'] as $block) {
            $this->assertIsArray($full[$block], $block);
        }
    }

    public function test_lists_are_limited_and_sorted(): void
    {
        $owner = $this->owner();
        $contract = $this->contract($this->vehicle($owner, 'A-0001'));
        foreach (range(4, 10) as $month) {
            RemunerationStatement::factory()->create(['vehicle_contract_id' => $contract->id, 'month' => sprintf('2026-%02d-01', $month)]);
        }
        foreach (range(1, 12) as $day) {
            Payment::factory()->onDay(sprintf('2026-10-%02d', $day))->create(['vehicle_contract_id' => $contract->id]);
        }
        Payment::factory()->onDay('2026-10-20')->status('cancelled')->create(['vehicle_contract_id' => $contract->id]);

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertSame(['2026-10', '2026-09', '2026-08', '2026-07', '2026-06'], array_column($json['statements'], 'month'));
        $this->assertNull($json['statements'][0]['balance_due']);
        $this->assertCount(10, $json['payments']);
        $this->assertSame('2026-10-12', $json['payments'][0]['payment_date']);
    }

    public function test_the_activity_shows_events_about_the_owner(): void
    {
        $owner = $this->owner();
        activity()->performedOn($owner)->event('owner.updated')->log('Propriétaire modifié');
        activity()->event('other.event')->log('Autre chose');

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertSame(['Propriétaire modifié'], array_column($json['activity'], 'description'));
    }

    public function test_an_owner_without_anything_has_an_empty_dossier(): void
    {
        $owner = $this->owner(['last_login_at' => null]);

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertSame([], $json['vehicles']);
        $this->assertSame([], $json['statements']);
        $this->assertSame([], $json['disbursements']);
        $this->assertSame([], $json['payments']);
        $this->assertNull($json['last_login_at']);
        $this->assertEquals(['disbursed' => 0, 'remaining' => 0, 'outstanding' => 0, 'vehicles_in_service' => 0, 'vehicles_total' => 0], $json['totals']);
    }

    /** Essai staging du 2026-11-04 : `last_login_at` sans cast arrivait en chaîne, et tout dossier d'un propriétaire déjà connecté tombait en 500. */
    public function test_the_dossier_of_an_owner_who_has_logged_in_gives_the_last_login(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['last_login_at' => '2026-11-03 08:15:00'])->save();

        $json = $this->dossier($owner->fresh())->assertOk()->json();

        $this->assertStringStartsWith('2026-11-03T08:15:00', $json['last_login_at']);
    }

    public function test_the_dossier_of_an_account_that_is_not_an_owner_is_a_404(): void
    {
        $admin = User::factory()->profil(Profil::Admin)->create();

        $this->dossier($admin)->assertNotFound();
    }

    public function test_a_malformed_identifier_is_a_404(): void
    {
        $this->api()->getJson('/api/v1/admin/owners/pas-un-uuid/dossier')->assertNotFound();
    }

    public function test_the_dossier_needs_view_owners(): void
    {
        $this->dossier($this->owner(), ['view-payments'])->assertForbidden();
    }

    // Mineur de la relecture du 2026-11-04 : le payé de chaque contrat se calcule une fois, en une requête.
    public function test_paid_totals_are_summed_once_for_all_contracts(): void
    {
        $owner = $this->owner();
        foreach (['A-0001', 'A-0002', 'A-0003'] as $number) {
            $contract = $this->contract($this->vehicle($owner, $number), ['total_amount' => 1_000_000]);
            Payment::factory()->onDay('2026-11-02')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 100_000]);
        }
        $sums = 0;
        DB::listen(function ($query) use (&$sums) {
            if (str_contains($query->sql, 'sum("net_amount")') || str_contains($query->sql, 'sum(net_amount)')) {
                $sums++;
            }
        });

        $json = $this->dossier($owner)->assertOk()->json();

        $this->assertSame(1, $sums);
        $this->assertEquals(2_700_000, $json['totals']['remaining']);
        $this->assertEquals(100_000, $json['vehicles'][0]['contract']['total_paid']);
        $this->assertSame(10, $json['vehicles'][0]['contract']['progress_percent']);
    }
}

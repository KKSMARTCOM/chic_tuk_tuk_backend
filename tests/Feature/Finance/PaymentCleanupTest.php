<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Database\Seeders\ReferenceRolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Nettoyer les paiements avant une reconstitution (2026-10-01) : annuler en groupe,
 * retrouver les anomalies de l'audit, masquer puis vider les annulés.
 */
class PaymentCleanupTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $contract;

    private DriverContract $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->contract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $this->agent = DriverContract::factory()->forVehicleContract($this->contract)->create(['start_date' => '2026-03-02']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(array $permissions = ['view-payments', 'edit-payments']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function pay(string $day, string $status = 'completed', array $extra = []): Payment
    {
        return Payment::factory()->onDay($day)->status($status)->create([
            'vehicle_contract_id' => $this->contract->id, 'driver_contract_id' => $this->agent->id, 'driver_id' => $this->agent->driver_id,
        ] + $extra);
    }

    // ----- Annuler en groupe --------------------------------------------------------------

    public function test_batch_cancellation_records_the_reason_and_is_silent_by_default(): void
    {
        $a = $this->pay('2026-03-02', 'pending');
        $b = $this->pay('2026-03-03');

        $this->api()->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$a->id, $b->id], 'reason' => 'Montant global remplacé par des paiements journaliers'])
            ->assertOk()->assertJson(['cancelled' => 2]);

        $this->assertSame(['cancelled', 'cancelled'], [$a->refresh()->status, $b->refresh()->status]);
        $this->assertStringContainsString('Annulé : Montant global remplacé par des paiements journaliers', $b->notes);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->agent->driver->user_id]);
        $this->assertSame(1, Activity::where('event', 'payment.batch_cancelled')->count());
        // Et une ligne par paiement, rattachée à lui, avec le motif (2026-10-08).
        $this->assertStringContainsString(
            'Montant global remplacé par des paiements journaliers',
            Activity::where('event', 'payment.cancelled')->where('subject_id', $b->id)->sole()->description,
        );
    }

    public function test_batch_cancellation_is_all_or_nothing(): void
    {
        $statement = RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create(['month' => '2026-03-01']);
        $counted = $this->pay('2026-03-02', 'completed', ['remuneration_statement_id' => $statement->id]);
        $free = $this->pay('2026-03-03');
        $already = $this->pay('2026-03-04', 'cancelled');

        $this->api()->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$free->id, $counted->id], 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_IN_VALIDATED_STATEMENT');
        $this->api()->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$free->id, $already->id], 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ALREADY_CANCELLED');
        $this->assertSame('completed', $free->refresh()->status);
    }

    public function test_batch_cancellation_needs_a_reason_and_the_permission(): void
    {
        $a = $this->pay('2026-03-02', 'pending');

        $this->api()->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$a->id]])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->api(['view-payments'])->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$a->id], 'reason' => 'x'])
            ->assertForbidden();
    }

    public function test_batch_cancellation_notifies_when_asked(): void
    {
        $a = $this->pay('2026-03-02', 'pending');

        $this->api()->postJson('/api/v1/admin/payments/cancel-batch', ['payment_ids' => [$a->id], 'reason' => 'x', 'notify_drivers' => true])->assertOk();

        $this->assertDatabaseHas('notifications', ['user_id' => $this->agent->driver->user_id]);
    }

    // ----- Les anomalies de l'audit, dans la liste ------------------------------------------

    private function listIds(string $anomaly): array
    {
        return collect($this->api(['view-payments'])->getJson("/api/v1/admin/payments?filter[anomaly]={$anomaly}")->assertOk()->json('payments'))
            ->pluck('id')->sort()->values()->all();
    }

    public function test_the_list_filters_each_anomaly_of_the_audit(): void
    {
        $normal = $this->pay('2026-03-02');
        $noMonth = $this->pay('2026-03-03', 'completed', ['payment_month' => null]);
        $noContract = Payment::factory()->onDay('2026-03-04')->create(['vehicle_contract_id' => null, 'driver_contract_id' => null]);
        VehiclePause::factory()->forContract($this->contract)->create(['start_date' => '2026-03-09', 'end_date' => '2026-03-09', 'reason_type' => 'technical']);
        $onStop = $this->pay('2026-03-09');
        $first = $this->pay('2026-03-10');
        $second = $this->pay('2026-03-10', 'pending');
        $pendingPast = $this->pay('2026-03-11', 'pending');

        $this->assertSame([$noMonth->id], $this->listIds('no_month'));
        $this->assertSame([$noContract->id], $this->listIds('no_contract'));
        $this->assertSame([$onStop->id], $this->listIds('stopped_day'));
        $this->assertSame([$second->id], $this->listIds('duplicate'));
        $this->assertSame(collect([$second->id, $pendingPast->id])->sort()->values()->all(), $this->listIds('pending_past'));
        $this->assertNotContains($normal->id, [...$this->listIds('duplicate'), ...$this->listIds('stopped_day')]);
        $this->assertNotContains($first->id, $this->listIds('duplicate'));
    }

    public function test_an_unknown_anomaly_is_a_400(): void
    {
        $this->api(['view-payments'])->getJson('/api/v1/admin/payments?filter[anomaly]=nimporte')->assertStatus(400);
    }

    public function test_the_audit_and_the_list_agree(): void
    {
        $this->pay('2026-03-10');
        $this->pay('2026-03-10');

        $this->artisan('app:audit-contract-payments')
            ->expectsOutputToContain('Doublons du même jour : 1')
            ->expectsOutputToContain('/admin/payments?filter[anomaly]=duplicate')
            ->assertSuccessful();
        $this->assertCount(1, $this->listIds('duplicate'));
    }

    // ----- Les annulés : masqués par défaut, puis vidés -------------------------------------

    public function test_cancelled_payments_are_hidden_by_default_and_shown_on_demand(): void
    {
        $live = $this->pay('2026-03-02');
        $cancelled = $this->pay('2026-03-03', 'cancelled');

        $default = collect($this->api(['view-payments'])->getJson('/api/v1/admin/payments')->json('payments'))->pluck('id')->all();
        $onDemand = collect($this->api(['view-payments'])->getJson('/api/v1/admin/payments?filter[status]=cancelled')->json('payments'))->pluck('id')->all();

        $this->assertSame([$live->id], $default);
        $this->assertSame([$cancelled->id], $onDemand);
    }

    public function test_purging_deletes_cancelled_contract_payments_only(): void
    {
        $cancelled = $this->pay('2026-03-03', 'cancelled');
        $live = $this->pay('2026-03-02');
        $commission = Payment::factory()->create(['payment_type' => 'commission', 'status' => 'cancelled', 'vehicle_contract_id' => null]);

        $this->api(['view-payments', 'edit-payments'])->deleteJson('/api/v1/admin/payments/cancelled')->assertForbidden();
        $this->api(['purge-payments'])->deleteJson('/api/v1/admin/payments/cancelled')->assertOk()->assertJson(['deleted' => 1]);

        $this->assertModelMissing($cancelled);
        $this->assertModelExists($live);
        $this->assertModelExists($commission);
        $this->assertSame(1, Activity::where('event', 'payment.cancelled_purged')->count());
    }

    public function test_only_the_admin_role_holds_the_purge_payments_permission(): void
    {
        $this->seed(ReferenceRolesAndPermissionsSeeder::class);

        $holders = Role::all()->filter(fn (Role $role) => $role->hasPermissionTo('purge-payments'))->pluck('name')->all();
        $this->assertSame(['admin'], $holders);
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** La date d'encaissement (spec 2026-10-01, §4.4 et §4.5). */
class PaymentCollectionDateTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $contract;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        config(['remuneration.first_month' => '2026-03']);
        $this->contract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $this->driver = Driver::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(array $permissions = ['edit-payments']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function pending(string $day): Payment
    {
        return Payment::factory()->onDay($day)->status('pending')->create(['vehicle_contract_id' => $this->contract->id, 'driver_id' => $this->driver->id]);
    }

    public function test_single_validation_records_the_collection_date_or_today(): void
    {
        $a = $this->pending('2026-03-02');
        $b = $this->pending('2026-03-03');

        $this->api()->postJson("/api/v1/admin/payments/{$a->id}/validate", ['collected_on' => '2026-03-31'])
            ->assertOk()->assertJsonPath('payment.collected_on', '2026-03-31');
        $this->api()->postJson("/api/v1/admin/payments/{$b->id}/validate")->assertOk();

        $this->assertSame('2026-03-31', $a->refresh()->collected_on->toDateString());
        $this->assertSame('2026-10-01', $b->refresh()->collected_on->toDateString());
    }

    public function test_a_future_collection_date_is_refused(): void
    {
        $a = $this->pending('2026-03-02');
        $this->api()->postJson("/api/v1/admin/payments/{$a->id}/validate", ['collected_on' => '2026-10-02'])
            ->assertStatus(422)->assertJsonValidationErrors('collected_on');
        $this->assertSame('pending', $a->refresh()->status);
    }

    public function test_a_collection_the_validated_statement_should_have_counted_is_refused(): void
    {
        RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create(['month' => '2026-04-01', 'issued_on' => '2026-05-04']);
        $march = $this->pending('2026-03-30');

        $this->api()->postJson("/api/v1/admin/payments/{$march->id}/validate", ['collected_on' => '2026-04-10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['collected_on' => 'La fiche d\'avril 2026 est déjà arrêtée au 04/05/2026 : cet encaissement aurait dû y figurer. Annulez-la d\'abord, ou choisissez une date postérieure.']);

        $this->api()->postJson("/api/v1/admin/payments/{$march->id}/validate", ['collected_on' => '2026-05-12'])->assertOk();
    }

    public function test_batch_validation_is_all_or_nothing_and_silent_by_default(): void
    {
        $a = $this->pending('2026-03-02');
        $b = $this->pending('2026-03-03');
        $done = Payment::factory()->onDay('2026-03-04')->status('completed')->create(['vehicle_contract_id' => $this->contract->id]);

        $this->api()->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id, $done->id], 'collected_on' => '2026-03-31'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_PENDING');
        $this->assertSame('pending', $a->refresh()->status);

        $this->api()->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id, $b->id], 'collected_on' => '2026-03-31'])
            ->assertOk()->assertJson(['validated' => 2]);
        $this->assertSame(['completed', '2026-03-31'], [$b->refresh()->status, $b->collected_on->toDateString()]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->driver->user_id]);
    }

    public function test_batch_validation_is_refused_whole_when_one_date_breaks_a_rule(): void
    {
        RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create(['month' => '2026-03-01', 'issued_on' => '2026-04-03']);
        $a = $this->pending('2026-03-02');

        $this->api()->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id], 'collected_on' => '2026-03-31'])
            ->assertStatus(422)->assertJsonValidationErrors('collected_on');
        $this->assertSame('pending', $a->refresh()->status);
    }

    public function test_batch_validation_notifies_when_asked(): void
    {
        $a = $this->pending('2026-03-02');
        $this->api()->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id], 'collected_on' => '2026-03-31', 'notify_drivers' => true])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->driver->user_id]);
    }

    public function test_correcting_the_collection_date_of_a_validated_payment(): void
    {
        $paid = Payment::factory()->onDay('2026-03-02')->create(['vehicle_contract_id' => $this->contract->id, 'collected_on' => '2026-10-01']);
        $this->api()->patchJson("/api/v1/admin/payments/{$paid->id}/collected-on", ['collected_on' => '2026-03-31'])->assertOk();
        $this->assertSame('2026-03-31', $paid->refresh()->collected_on->toDateString());

        $statement = RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create(['month' => '2026-03-01']);
        $paid->update(['remuneration_statement_id' => $statement->id]);
        $this->api()->patchJson("/api/v1/admin/payments/{$paid->id}/collected-on", ['collected_on' => '2026-03-30'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_IN_VALIDATED_STATEMENT');

        $pending = $this->pending('2026-03-05');
        $this->api()->patchJson("/api/v1/admin/payments/{$pending->id}/collected-on", ['collected_on' => '2026-03-30'])
            ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_NOT_COLLECTED');
    }

    public function test_the_batch_needs_the_edit_permission(): void
    {
        $a = $this->pending('2026-03-02');
        $this->api([])->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id], 'collected_on' => '2026-03-31'])->assertForbidden();
    }

    public function test_the_batch_journal_totals_net_amounts_like_the_generation(): void
    {
        $a = Payment::factory()->onDay('2026-03-02')->status('pending')->create(['vehicle_contract_id' => $this->contract->id, 'amount' => 6112, 'net_amount' => 5871]);
        $b = Payment::factory()->onDay('2026-03-03')->status('pending')->create(['vehicle_contract_id' => $this->contract->id, 'amount' => 6112, 'net_amount' => 5871]);

        $this->api()->postJson('/api/v1/admin/payments/validate-batch', ['payment_ids' => [$a->id, $b->id], 'collected_on' => '2026-03-31'])->assertOk();

        $this->assertEquals(11742, \Spatie\Activitylog\Models\Activity::where('event', 'payment.batch_validated')->sole()->properties['total']);
    }

    public function test_a_payment_before_the_first_month_is_never_blocked_by_a_statement(): void
    {
        // Un paiement d'avant la mise en service n'entre dans aucune fiche, même en recouvré :
        // aucune fiche ne peut l'avoir « manqué ».
        config(['remuneration.first_month' => '2026-04']);
        RemunerationStatement::factory()->for($this->contract, 'contract')->validated()->create(['month' => '2026-04-01', 'issued_on' => '2026-05-04']);
        $march = $this->pending('2026-03-30');

        $this->api()->postJson("/api/v1/admin/payments/{$march->id}/validate", ['collected_on' => '2026-04-10'])->assertOk();
    }
}

<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * La « Situation de l'agent » (2026-10-02) : ses commissions, ses paiements de contrat et
 * ses abonnements, sur la fiche d'un paiement comme dans son dossier. Remplace le
 * « Résumé de l'agent », qui ne parlait que des commissions sans le dire.
 */
class DriverSituationTest extends TestCase
{
    use RefreshDatabase;

    private DriverContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');

        $vehicle = Vehicle::factory()->create(['vehicle_number' => '2GC4662 RB']);
        $this->contract = DriverContract::factory()
            ->forVehicleContract(VehicleContract::factory()->forVehicle($vehicle)->create())
            ->create(['start_date' => '2026-09-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function fetch(string $token, string $uri)
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
    }

    private function contractPayment(string $day, string $status, ?string $collectedOn = null, ?DriverContract $contract = null): Payment
    {
        $contract ??= $this->contract;

        return Payment::factory()->onDay($day)->status($status)->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'payment_type' => 'contract',
            'amount' => 6112,
            'collected_on' => $collectedOn,
        ]);
    }

    /** Commissions : 1 000 dues (500 annulées ne comptent pas), 300 payées. */
    private function commissions(): Payment
    {
        $driver = $this->contract->driver;
        foreach ([[1000, 'active'], [500, 'cancelled']] as [$amount, $status]) {
            Commission::create([
                'driver_id' => $driver->id,
                'booking_id' => Booking::factory()->completed($driver)->create()->id,
                'amount' => $amount,
                'date' => '2026-10-01',
                'status' => $status,
            ]);
        }

        return Payment::factory()->create(['driver_id' => $driver->id, 'payment_type' => 'commission', 'amount' => 300]);
    }

    /** Un abonnement de deux courses terminées, 800 chacune ; 1 000 déjà versés à l'agent. */
    private function subscription(): void
    {
        $driver = $this->contract->driver;
        $parent = Booking::factory()->completed($driver)->create(['is_recurring' => true, 'driver_earning' => 800]);
        Booking::factory()->completed($driver)->create(['parent_booking_id' => $parent->id, 'is_recurring' => false, 'driver_earning' => 800]);
        Payment::factory()->create(['driver_id' => $driver->id, 'payment_type' => 'subscription_revenue', 'amount' => 1000]);
    }

    public function test_the_payment_detail_gives_the_three_situations(): void
    {
        $payment = $this->commissions();
        $this->subscription();
        $this->contractPayment('2026-10-10', 'completed', '2026-10-10');
        $this->contractPayment('2026-10-12', 'completed', '2026-10-13');
        $this->contractPayment('2026-10-13', 'pending');
        $this->contractPayment('2026-10-15', 'pending');
        $this->contractPayment('2026-10-14', 'cancelled');

        $this->fetch($this->token(['view-payments']), "/api/v1/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('driver_situation.commissions.due', 1000)
            ->assertJsonPath('driver_situation.commissions.paid', 300)
            ->assertJsonPath('driver_situation.commissions.balance', 700)
            ->assertJsonPath('driver_situation.commissions.active_count', 1)
            ->assertJsonPath('driver_situation.contract.vehicle_number', '2GC4662 RB')
            ->assertJsonPath('driver_situation.contract.is_active', true)
            ->assertJsonPath('driver_situation.contract.validated_count', 2)
            ->assertJsonPath('driver_situation.contract.validated_amount', 12_224)
            ->assertJsonPath('driver_situation.contract.pending_count', 2)
            ->assertJsonPath('driver_situation.contract.pending_amount', 12_224)
            // Le jour même n'est pas en retard : seul le 13 l'est.
            ->assertJsonPath('driver_situation.contract.late_count', 1)
            ->assertJsonPath('driver_situation.contract.late_amount', 6112)
            ->assertJsonPath('driver_situation.contract.last_collected_on', '2026-10-13')
            ->assertJsonPath('driver_situation.subscriptions.count', 1)
            ->assertJsonPath('driver_situation.subscriptions.completed_bookings', 2)
            ->assertJsonPath('driver_situation.subscriptions.due', 1600)
            ->assertJsonPath('driver_situation.subscriptions.paid', 1000)
            ->assertJsonPath('driver_situation.subscriptions.balance', 600);
    }

    /** Périmètre décidé le 2026-10-02 : le contrat EN COURS ; un ancien contrat ne compte pas. */
    public function test_the_contract_situation_is_the_current_contract_only(): void
    {
        $old = DriverContract::factory()->create([
            'driver_id' => $this->contract->driver_id, 'status' => 'ended',
            'start_date' => '2026-01-01', 'end_date' => '2026-08-31',
        ]);
        $this->contractPayment('2026-08-10', 'pending', null, $old);
        $payment = $this->contractPayment('2026-10-10', 'completed', '2026-10-10');

        $this->fetch($this->token(['view-payments']), "/api/v1/admin/payments/{$payment->id}")
            ->assertJsonPath('driver_situation.contract.validated_count', 1)
            ->assertJsonPath('driver_situation.contract.pending_count', 0)
            ->assertJsonPath('driver_situation.contract.late_count', 0);
    }

    /** Sans contrat en cours, le dernier contrat ; sans contrat du tout, rien. */
    public function test_without_a_current_contract_the_last_one_then_none(): void
    {
        $this->contract->update(['status' => 'ended', 'end_date' => '2026-10-01']);
        $payment = $this->contractPayment('2026-09-10', 'completed', '2026-09-10');
        $token = $this->token(['view-payments']);

        $this->fetch($token, "/api/v1/admin/payments/{$payment->id}")
            ->assertJsonPath('driver_situation.contract.is_active', false)
            ->assertJsonPath('driver_situation.contract.validated_count', 1);

        $alone = Driver::factory()->create();
        $commission = Payment::factory()->create(['driver_id' => $alone->id, 'payment_type' => 'commission', 'amount' => 100]);
        $this->fetch($token, "/api/v1/admin/payments/{$commission->id}")
            ->assertOk()
            ->assertJsonPath('driver_situation.contract', null)
            ->assertJsonPath('driver_situation.subscriptions.count', 0);
    }

    public function test_the_driver_file_carries_the_same_situation(): void
    {
        $this->contractPayment('2026-10-13', 'pending');

        $this->fetch($this->token(['view-drivers']), "/api/v1/admin/drivers/{$this->contract->driver_id}")
            ->assertOk()
            ->assertJsonPath('situation.contract.late_count', 1)
            ->assertJsonPath('situation.commissions.due', 0);
    }

    /** La page « Voir tous ses paiements » est retirée : la liste filtrée par agent la remplace. */
    public function test_the_driver_payments_page_is_gone(): void
    {
        $this->fetch($this->token(['view-payments']), "/api/v1/admin/drivers/{$this->contract->driver_id}/payments")
            ->assertNotFound();
    }

    /** Les commissions dues d'un agent, que listait la page retirée : la liste des commissions les filtre. */
    public function test_the_commissions_list_filters_due_commissions_of_a_driver(): void
    {
        $this->commissions();
        $other = Driver::factory()->create();
        Commission::create([
            'driver_id' => $other->id, 'booking_id' => Booking::factory()->completed($other)->create()->id,
            'amount' => 50, 'date' => '2026-10-01', 'status' => 'active',
        ]);

        $page = $this->fetch($this->token(['view-commissions']), '/api/v1/admin/commissions?'.http_build_query([
            'filter' => ['driver_id' => $this->contract->driver_id, 'status' => 'active'],
        ]))->assertOk();

        $page->assertJsonCount(1, 'commissions')->assertJsonPath('commissions.0.amount', 1000);
        // Les agents du filtre, comme la liste des paiements.
        $this->assertContains($this->contract->driver_id, collect($page->json('drivers'))->pluck('id'));
    }
}

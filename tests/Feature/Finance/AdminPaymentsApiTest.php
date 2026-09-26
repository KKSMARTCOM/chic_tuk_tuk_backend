<?php

namespace Tests\Feature\Finance;

use App\Consts\VehicleContractConsts;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les paiements vus de l'administration — ex-Admin\PaymentController (P2).
 *
 * ⚠️ Règles décidées le 2026-09-26 :
 *  - on ne modifie et on ne supprime qu'un paiement EN ATTENTE ; un paiement validé
 *    s'annule ;
 *  - la modification ne change ni le type, ni l'agent, ni le contrat du paiement ;
 *  - on ne valide qu'un paiement en attente ; on n'annule pas deux fois ;
 *  - les plafonds (commission restante, solde du contrat véhicule) ne comptent plus les
 *    paiements ANNULÉS comme payés.
 */
class AdminPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Valider ou annuler notifie l'agent : aucun envoi réel pendant les tests.
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
    }

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

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Un agent sous contrat actif, sur un véhicule sous contrat véhicule de 24 mois. */
    private function contractedDriver(float $vehicleTotal = 3_100_000): DriverContract
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create(['total_amount' => $vehicleTotal]);

        return DriverContract::factory()->forVehicleContract($vehicleContract)->create();
    }

    private function commission(Driver $driver, float $amount): Commission
    {
        return Commission::create([
            'driver_id' => $driver->id,
            'booking_id' => Booking::factory()->completed($driver)->create()->id,
            'amount' => $amount,
            'date' => '2026-09-20',
            'status' => 'active',
        ]);
    }

    /** Un paiement journalier de contrat, tel que `app:generate-daily` le crée. */
    private function dailyPayment(DriverContract $contract, string $status = 'pending'): Payment
    {
        return Payment::factory()->status($status)->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'payment_type' => 'contract',
            'amount' => VehicleContractConsts::AMOUNTS[24],
            'net_amount' => VehicleContractConsts::AMOUNTS[24] - VehicleContractConsts::TAXE[24],
            'payment_method' => 'other',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'payment_type' => 'commission',
            'amount' => 500,
            'payment_method' => 'mobile_money',
            'payment_date' => '2026-09-25',
        ], $overrides);
    }

    // ----- Lectures ----------------------------------------------------------------------

    public function test_reads_require_view_payments(): void
    {
        $payment = $this->dailyPayment($this->contractedDriver());
        $token = $this->login(['view-commissions']);

        $this->asBearer($token)->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->asBearer($token)->getJson("/api/v1/admin/payments/{$payment->id}")->assertForbidden();
    }

    public function test_the_list_filters_and_announces_what_each_payment_allows(): void
    {
        $contract = $this->contractedDriver();
        $pending = $this->dailyPayment($contract);
        $completed = $this->dailyPayment($contract, 'completed');
        $cancelled = $this->dailyPayment($contract, 'cancelled');

        $response = $this->asBearer($this->login(['view-payments']))
            ->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('stats.pending_payments_count', 1)
            ->assertJsonPath('stats.validated_payments_count', 1)
            ->assertJsonPath('stats.cancelled_payments_count', 1);

        $rows = collect($response->json('payments'))->keyBy('id');
        $this->assertTrue($rows[$pending->id]['is_editable']);
        $this->assertTrue($rows[$pending->id]['can_validate']);
        $this->assertTrue($rows[$pending->id]['is_deletable']);
        $this->assertFalse($rows[$completed->id]['can_validate']);
        $this->assertTrue($rows[$completed->id]['can_cancel']);
        $this->assertFalse($rows[$completed->id]['is_deletable']);
        $this->assertFalse($rows[$cancelled->id]['can_cancel']);
        $this->assertSame(24, $rows[$pending->id]['contract_months']);

        $this->asBearer($this->login(['view-payments']))
            ->getJson('/api/v1/admin/payments?status=pending')
            ->assertJsonCount(1, 'payments');
    }

    public function test_search_does_not_escape_the_other_filters(): void
    {
        // Défaut corrigé : `->orWhere('reference_number', …)` n'était pas groupé, et une
        // référence trouvée passait outre le filtre d'agent et de statut.
        $mine = $this->contractedDriver();
        $other = $this->contractedDriver();
        Payment::factory()->create(['driver_id' => $other->driver_id, 'payment_type' => 'commission', 'reference_number' => 'REF-42']);

        $this->asBearer($this->login(['view-payments']))
            ->getJson('/api/v1/admin/payments?'.http_build_query(['driver_id' => $mine->driver_id, 'search' => 'REF-42']))
            ->assertOk()
            ->assertJsonCount(0, 'payments');
    }

    public function test_the_detail_and_the_driver_file_summarise_commissions(): void
    {
        $contract = $this->contractedDriver();
        $this->commission($contract->driver, 1000);
        $payment = Payment::factory()->create(['driver_id' => $contract->driver_id, 'payment_type' => 'commission', 'amount' => 300]);
        Payment::factory()->status('cancelled')->create(['driver_id' => $contract->driver_id, 'payment_type' => 'commission', 'amount' => 200]);

        $token = $this->login(['view-payments']);

        $this->asBearer($token)->getJson("/api/v1/admin/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('driver_summary.total_due', 1000)
            ->assertJsonPath('driver_summary.total_paid', 300)
            ->assertJsonPath('driver_summary.balance_due', 700);

        $this->asBearer($token)->getJson("/api/v1/admin/drivers/{$contract->driver_id}/payments")
            ->assertOk()
            ->assertJsonPath('summary.balance_due', 700)
            ->assertJsonCount(2, 'payments')
            ->assertJsonCount(1, 'commissions');
    }

    public function test_payable_drivers_are_those_under_active_contract(): void
    {
        $contract = $this->contractedDriver();
        $without = Driver::factory()->create();

        $ids = collect($this->asBearer($this->login(['create-payments']))
            ->getJson('/api/v1/admin/payments/payable-drivers')
            ->assertOk()
            ->json())->pluck('id');

        $this->assertContains($contract->driver_id, $ids);
        $this->assertNotContains($without->id, $ids);
    }

    // ----- Création ----------------------------------------------------------------------

    public function test_a_commission_payment_is_completed_and_attached_to_the_active_contract(): void
    {
        $contract = $this->contractedDriver();
        $this->commission($contract->driver, 1000);

        $this->asBearer($this->login(['create-payments']))
            ->postJson('/api/v1/admin/payments', $this->payload(['driver_id' => $contract->driver_id]))
            ->assertCreated()
            ->assertJsonPath('payment.status', 'completed')
            ->assertJsonPath('payment.payment_type', 'commission');

        $this->assertDatabaseHas('payments', [
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'amount' => 500,
        ]);
    }

    public function test_creation_requires_its_permission(): void
    {
        $contract = $this->contractedDriver();

        $this->asBearer($this->login(['view-payments', 'edit-payments']))
            ->postJson('/api/v1/admin/payments', $this->payload(['driver_id' => $contract->driver_id]))
            ->assertForbidden();
    }

    public function test_cancelled_payments_do_not_eat_the_commission_left_to_pay(): void
    {
        // Défaut corrigé : un paiement ANNULÉ comptait comme payé, et bloquait le vrai.
        $contract = $this->contractedDriver();
        $this->commission($contract->driver, 1000);
        Payment::factory()->status('cancelled')->create(['driver_id' => $contract->driver_id, 'payment_type' => 'commission', 'amount' => 800]);

        $token = $this->login(['create-payments']);

        $this->asBearer($token)
            ->postJson('/api/v1/admin/payments', $this->payload(['driver_id' => $contract->driver_id, 'amount' => 900]))
            ->assertCreated();

        $this->asBearer($token)
            ->postJson('/api/v1/admin/payments', $this->payload(['driver_id' => $contract->driver_id, 'amount' => 200]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_EXCEEDS_BALANCE');
    }

    public function test_a_contract_payment_carries_its_net_amount_and_respects_the_contract_balance(): void
    {
        $contract = $this->contractedDriver(vehicleTotal: 10_000);
        $this->dailyPayment($contract, 'cancelled');

        $token = $this->login(['create-payments']);

        $this->asBearer($token)
            ->postJson('/api/v1/admin/payments', $this->payload([
                'driver_id' => $contract->driver_id,
                'payment_type' => 'contract',
                'amount' => 10_000,
            ]))
            ->assertCreated()
            ->assertJsonPath('payment.net_amount', 10_000 - VehicleContractConsts::TAXE[24]);

        $this->asBearer($token)
            ->postJson('/api/v1/admin/payments', $this->payload([
                'driver_id' => $contract->driver_id,
                'payment_type' => 'contract',
                'amount' => 1,
            ]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_EXCEEDS_BALANCE');
    }

    // ----- Modification ------------------------------------------------------------------

    public function test_editing_a_pending_payment_keeps_its_type_contract_and_status(): void
    {
        // Défaut corrigé : le type n'étant pas dans le formulaire, un paiement de contrat
        // redevenait une commission ; son statut retombait à « en attente » ; et il était
        // rattaché au contrat ACTUEL de l'agent.
        $contract = $this->contractedDriver();
        $payment = $this->dailyPayment($contract);

        $this->asBearer($this->login(['edit-payments']))
            ->putJson("/api/v1/admin/payments/{$payment->id}", [
                'amount' => 5000,
                'payment_method' => 'cash',
                'payment_date' => '2026-09-24',
                'notes' => 'Réglé en partie',
            ])
            ->assertOk()
            ->assertJsonPath('payment.payment_type', 'contract')
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.net_amount', 5000 - VehicleContractConsts::TAXE[24]);

        $payment->refresh();
        $this->assertSame($contract->id, $payment->driver_contract_id);
        $this->assertSame('cash', $payment->payment_method);
    }

    public function test_only_a_pending_payment_is_edited(): void
    {
        $payment = $this->dailyPayment($this->contractedDriver(), 'completed');

        $this->asBearer($this->login(['edit-payments']))
            ->putJson("/api/v1/admin/payments/{$payment->id}", [
                'amount' => 5000,
                'payment_method' => 'cash',
                'payment_date' => '2026-09-24',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_NOT_EDITABLE');
    }

    // ----- Validation, annulation --------------------------------------------------------

    public function test_validating_a_pending_payment_notifies_the_driver(): void
    {
        $contract = $this->contractedDriver();
        $payment = $this->dailyPayment($contract);

        $this->asBearer($this->login(['edit-payments']))
            ->postJson("/api/v1/admin/payments/{$payment->id}/validate")
            ->assertOk()
            ->assertJsonPath('payment.status', 'completed');

        $this->assertSame(1, Notification::where('user_id', $contract->driver->user_id)->where('title', 'Paiement validé')->count());
    }

    public function test_a_cancelled_payment_is_not_validated(): void
    {
        $payment = $this->dailyPayment($this->contractedDriver(), 'cancelled');

        $this->asBearer($this->login(['edit-payments']))
            ->postJson("/api/v1/admin/payments/{$payment->id}/validate")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_NOT_PENDING');

        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    public function test_a_validated_payment_is_cancelled_once_and_the_driver_is_told(): void
    {
        $contract = $this->contractedDriver();
        $payment = $this->dailyPayment($contract, 'completed');
        $token = $this->login(['edit-payments']);

        $this->asBearer($token)
            ->postJson("/api/v1/admin/payments/{$payment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('payment.status', 'cancelled');

        $this->asBearer($token)
            ->postJson("/api/v1/admin/payments/{$payment->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_ALREADY_CANCELLED');

        $this->assertSame(1, Notification::where('user_id', $contract->driver->user_id)->where('title', 'Paiement annulé')->count());
    }

    // ----- Suppression -------------------------------------------------------------------

    public function test_only_a_pending_payment_is_deleted_and_only_with_delete_payments(): void
    {
        $contract = $this->contractedDriver();
        $pending = $this->dailyPayment($contract);
        $completed = $this->dailyPayment($contract, 'completed');

        $this->asBearer($this->login(['edit-payments']))
            ->deleteJson("/api/v1/admin/payments/{$pending->id}")
            ->assertForbidden();

        $token = $this->login(['delete-payments']);

        $this->asBearer($token)->deleteJson("/api/v1/admin/payments/{$completed->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYMENT_NOT_DELETABLE');

        $this->asBearer($token)->deleteJson("/api/v1/admin/payments/{$pending->id}")->assertNoContent();

        $this->assertDatabaseMissing('payments', ['id' => $pending->id]);
        $this->assertDatabaseHas('payments', ['id' => $completed->id]);
    }
}

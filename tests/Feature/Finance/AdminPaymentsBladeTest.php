<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le chemin Blade des paiements, aligné sur l'API le 2026-09-26 : une permission par
 * route — la ressource, la validation et l'annulation n'en exigeaient aucune — et les
 * règles du service.
 */
class AdminPaymentsBladeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
    }

    private function adminWith(array $permissions): User
    {
        $admin = User::factory()->profil(Profil::Admin)->create(['email' => Str::uuid().'@example.test']);

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    private function pendingContractPayment(): Payment
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $contract = DriverContract::factory()->forVehicleContract($vehicleContract)->create();

        return Payment::factory()->status('pending')->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $vehicleContract->id,
            'payment_type' => 'contract',
        ]);
    }

    public function test_each_route_requires_its_own_permission(): void
    {
        $payment = $this->pendingContractPayment();
        $reader = $this->adminWith(['view-payments']);

        $this->actingAs($reader)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($reader)->post(route('admin.payments.store'), [])->assertForbidden();
        $this->actingAs($reader)->put(route('admin.payments.update', $payment), [])->assertForbidden();
        $this->actingAs($reader)->patch(route('admin.payments.validate', $payment))->assertForbidden();
        $this->actingAs($reader)->patch(route('admin.payments.cancel', $payment))->assertForbidden();
        $this->actingAs($reader)->delete(route('admin.payments.destroy', $payment))->assertForbidden();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_the_blade_edit_keeps_the_contract_type(): void
    {
        $payment = $this->pendingContractPayment();

        $this->actingAs($this->adminWith(['edit-payments']))
            ->put(route('admin.payments.update', $payment), [
                'driver_id' => $payment->driver_id,
                'payment_type' => 'commission',
                'amount' => 5000,
                'payment_method' => 'cash',
                'payment_date' => '2026-09-24',
            ])
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('contract', $payment->payment_type);
        $this->assertSame('pending', $payment->status);
    }

    public function test_the_blade_refuses_to_validate_a_cancelled_payment(): void
    {
        $payment = $this->pendingContractPayment();
        $payment->update(['status' => 'cancelled']);

        $this->actingAs($this->adminWith(['edit-payments']))
            ->patch(route('admin.payments.validate', $payment))
            ->assertSessionHas('error');

        $this->assertSame('cancelled', $payment->fresh()->status);
    }
}

<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\AssignInternalDriver;
use App\Domains\Fleet\Application\Actions\EndInternalAssignment;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\InternalAssignment;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Spec 2026-10-09, §3.2 et §7. */
class InternalAssignmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private function assign(VehicleContract $contract, Driver $driver, string $start = '2026-10-05'): InternalAssignment
    {
        return app(AssignInternalDriver::class)($contract, $driver, $start, null, null);
    }

    private function code(callable $call): ?string
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return null;
    }

    public function test_an_internal_driver_goes_on_a_pending_contract(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        $assignment = $this->assign($contract, Driver::factory()->create());

        $this->assertSame('2026-10-05', $assignment->start_date->toDateString());
        $this->assertNull($assignment->end_date);
    }

    public function test_a_vehicle_with_an_agent_under_contract_is_refused(): void
    {
        $contract = VehicleContract::factory()->create();
        DriverContract::factory()->create(['vehicle_contract_id' => $contract->id, 'vehicle_id' => $contract->vehicle_id, 'status' => 'active']);

        $this->assertSame('VEHICLE_ALREADY_DRIVEN', $this->code(fn () => $this->assign($contract, Driver::factory()->create())));
    }

    public function test_a_vehicle_with_an_ongoing_assignment_is_refused(): void
    {
        $contract = VehicleContract::factory()->create();
        $this->assign($contract, Driver::factory()->create());

        $this->assertSame('VEHICLE_ALREADY_DRIVEN', $this->code(fn () => $this->assign($contract, Driver::factory()->create())));
    }

    public function test_a_driver_already_assigned_elsewhere_is_refused(): void
    {
        $driver = Driver::factory()->create();
        DriverContract::factory()->create(['driver_id' => $driver->id, 'status' => 'active']);

        $this->assertSame('DRIVER_ALREADY_ASSIGNED', $this->code(fn () => $this->assign(VehicleContract::factory()->create(), $driver)));
    }

    public function test_a_closed_contract_is_refused(): void
    {
        $contract = VehicleContract::factory()->create(['status' => 'completed']);

        $this->assertSame('VEHICLE_CONTRACT_CLOSED', $this->code(fn () => $this->assign($contract, Driver::factory()->create())));
    }

    public function test_a_start_before_an_active_contract_is_refused(): void
    {
        $contract = VehicleContract::factory()->create(['start_date' => '2026-10-01']);

        $this->expectException(ValidationException::class);
        $this->assign($contract, Driver::factory()->create(), '2026-09-30');
    }

    public function test_ending_records_the_date_the_reason_and_the_author(): void
    {
        $assignment = $this->assign(VehicleContract::factory()->create(), Driver::factory()->create());

        $ended = app(EndInternalAssignment::class)($assignment, '2026-10-20', 'manual', null);

        $this->assertSame('2026-10-20', $ended->end_date->toDateString());
        $this->assertSame('manual', $ended->ended_reason);
    }

    public function test_ending_before_the_start_is_refused_and_keeps_it_ongoing(): void
    {
        $assignment = $this->assign(VehicleContract::factory()->create(), Driver::factory()->create());

        try {
            app(EndInternalAssignment::class)($assignment, '2026-10-04', 'manual', null);
            $this->fail('La fin avant le début devait être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
        }
        $this->assertNull($assignment->fresh()->end_date);
    }

    public function test_an_ended_assignment_cannot_be_ended_again(): void
    {
        $assignment = InternalAssignment::factory()->ended('2026-10-10')->create();

        $this->assertSame('INTERNAL_ASSIGNMENT_ENDED', $this->code(fn () => app(EndInternalAssignment::class)($assignment, '2026-10-20', 'manual', null)));
    }
}

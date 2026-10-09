<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\TakeOverVehicle;
use App\Models\Driver;
use App\Models\InternalAssignment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Spec 2026-10-09, §6 et §5.2. */
class InternalAssignmentJournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_takeover_logs_the_end_and_the_activation_and_notifies_the_owner(): void
    {
        $owner = User::factory()->create();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id, 'vehicle_number' => '2EV2338RB']);
        $contract = VehicleContract::factory()->create(['vehicle_id' => $vehicle->id, 'owner_id' => $owner->id, 'status' => 'pending', 'start_date' => null]);
        InternalAssignment::factory()->for($contract, 'vehicleContract')->create(['start_date' => '2026-10-01']);

        app(TakeOverVehicle::class)($contract, '2026-10-12', Driver::factory()->create());

        $this->assertStringContainsString('2EV2338RB', Activity::where('event', 'vehicle.internal_assignment_ended')->sole()->description);
        $this->assertStringContainsString('12/10/2026', Activity::where('event', 'vehicle.contract_activated')->sole()->description);
        $this->assertStringContainsString(
            'Votre véhicule 2EV2338RB est en service depuis le 12/10/2026',
            Notification::where('user_id', $owner->id)->sole()->message,
        );
    }
}

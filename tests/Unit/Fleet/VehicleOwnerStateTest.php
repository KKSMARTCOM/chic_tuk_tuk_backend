<?php

namespace Tests\Unit\Fleet;

use App\Domains\Fleet\Domain\VehicleOwnerState;
use App\Models\VehiclePause;
use PHPUnit\Framework\TestCase;

/** Les trois états d'un véhicule que le propriétaire lit (maquette, spec §6.1). */
class VehicleOwnerStateTest extends TestCase
{
    public function test_the_three_states(): void
    {
        $pause = fn (string $reason) => (new VehiclePause)->forceFill(['reason_type' => $reason]);

        $this->assertSame('active', VehicleOwnerState::of(null));
        $this->assertSame('paused', VehicleOwnerState::of($pause('agent_leave')));
        $this->assertSame('paused', VehicleOwnerState::of($pause('technical')));
        // En attente d'un nouvel agent : le seul cas « Immobilisé » de la maquette.
        $this->assertSame('immobilized', VehicleOwnerState::of($pause('agent_change')));
    }
}

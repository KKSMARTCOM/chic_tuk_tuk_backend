<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le chemin Blade des commissions, aligné sur l'API le 2026-09-26 : la « suppression »
 * n'exigeait aucune permission et effaçait la commission ; elle l'annule désormais, sous
 * `delete-commissions`.
 */
class AdminCommissionsBladeTest extends TestCase
{
    use RefreshDatabase;

    private function adminWith(array $permissions): User
    {
        $admin = User::factory()->profil(Profil::Admin)->create(['email' => Str::uuid().'@example.test']);

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    public function test_the_blade_cancels_instead_of_deleting_and_requires_its_permission(): void
    {
        $driver = Driver::factory()->create();
        $commission = Commission::create([
            'driver_id' => $driver->id,
            'booking_id' => Booking::factory()->completed($driver)->create()->id,
            'amount' => 750,
            'date' => '2026-09-20',
            'status' => 'active',
        ]);

        $this->actingAs($this->adminWith(['view-commissions']))
            ->patch(route('admin.commissions.destroy', $commission))
            ->assertForbidden();

        $this->actingAs($this->adminWith(['delete-commissions']))
            ->patch(route('admin.commissions.destroy', $commission))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('commissions', ['id' => $commission->id, 'status' => 'cancelled']);
    }
}

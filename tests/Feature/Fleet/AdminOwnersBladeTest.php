<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Les défauts du chemin Blade `/admin/owners` corrigés avec la transposition, le
 * 2026-09-25 — le code étant partagé, ou les deux copies devant dire la même chose.
 */
class AdminOwnersBladeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'proprietaire', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        return User::factory()->profil(Profil::Admin)->create();
    }

    public function test_a_phone_already_used_by_an_agent_is_a_validation_error(): void
    {
        $agent = Driver::factory()->create()->user;

        $this->actingAs($this->admin())
            ->from(route('admin.owners.create'))
            ->post(route('admin.owners.store'), [
                'name' => 'Nouveau Propriétaire',
                'phone' => $agent->phone,
                'password' => 'MotDePasse2026!',
            ])
            ->assertRedirect(route('admin.owners.create'))
            ->assertSessionHasErrors(['phone']);
    }

    public function test_the_edit_screen_shows_the_vehicle_notes(): void
    {
        $owner = User::factory()->profil(Profil::Owner)->create();
        $owner->assignRole('proprietaire');
        Vehicle::factory()->create(['owner_id' => $owner->id, 'notes' => 'Rétroviseur à changer']);

        $this->actingAs($this->admin())
            ->get(route('admin.owners.edit', $owner))
            ->assertOk()
            ->assertSee('Rétroviseur à changer');
    }
}

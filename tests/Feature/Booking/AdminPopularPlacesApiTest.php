<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\PopularPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Les lieux populaires dans les Réglages (spec 2026-10-27-recherche-de-lieux, §5.1). */
class AdminPopularPlacesApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/popular-places';

    private function as(array $permissions = ['manage-business-settings']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function payload(array $overrides = []): array
    {
        return ['name' => 'Complexe scolaire Les Cracks', 'landmark' => 'Fidjrossè, Cotonou', 'aliases' => ['CS Les Cracks'],
            'lat' => 6.3561, 'lng' => 2.3728, 'is_active' => true, ...$overrides];
    }

    private function place(string $name): PopularPlace
    {
        return PopularPlace::create(['name' => $name, 'lat' => 6.36, 'lng' => 2.40]);
    }

    public function test_only_the_administrator_manages_popular_places(): void
    {
        $place = $this->place('Dantokpa');
        $user = $this->as(['view-payments']);

        $user->getJson(self::URL)->assertForbidden();
        $user->postJson(self::URL, $this->payload())->assertForbidden();
        $user->putJson(self::URL."/{$place->id}", $this->payload())->assertForbidden();
        $user->deleteJson(self::URL."/{$place->id}")->assertForbidden();
    }

    public function test_add_update_and_remove_a_place(): void
    {
        $this->place('Dantokpa');
        $admin = $this->as();

        $admin->getJson(self::URL)->assertOk()->assertJsonPath('places.0.name', 'Dantokpa');

        $added = $admin->postJson(self::URL, $this->payload())->assertOk();
        $this->assertSame(['Complexe scolaire Les Cracks', 'Dantokpa'], array_column($added->json('places'), 'name'));
        $this->assertSame(['CS Les Cracks'], $added->json('places.0.aliases'));
        $this->assertSame(6.3561, $added->json('places.0.lat'));
        $id = $added->json('places.0.id');
        $this->assertNotNull(PopularPlace::find($id)->created_by);

        $admin->putJson(self::URL."/{$id}", $this->payload(['name' => 'Les Cracks', 'is_active' => false, 'aliases' => []]))
            ->assertOk()
            ->assertJsonPath('places.1.name', 'Les Cracks')
            ->assertJsonPath('places.1.is_active', false)
            ->assertJsonPath('places.1.aliases', []);

        $admin->deleteJson(self::URL."/{$id}")->assertOk()->assertJsonCount(1, 'places');
        $this->assertNull(PopularPlace::find($id));
    }

    public function test_a_name_differing_only_by_case_or_accents_is_refused(): void
    {
        $place = $this->place('Fidjrossè');
        $admin = $this->as();

        $admin->postJson(self::URL, $this->payload(['name' => 'FIDJROSSE']))
            ->assertStatus(422)->assertJsonPath('errors.name.0', 'Ce lieu existe déjà');
        $admin->putJson(self::URL."/{$place->id}", $this->payload(['name' => 'Fidjrossè']))->assertOk();
    }

    public function test_coordinates_outside_the_zone_are_refused(): void
    {
        $this->as()->postJson(self::URL, $this->payload(['lat' => 14, 'lng' => 5]))
            ->assertStatus(422)
            ->assertJsonPath('errors.lat.0', 'Le lieu est en dehors de la zone desservie.')
            ->assertJsonPath('errors.lng.0', 'Le lieu est en dehors de la zone desservie.');
    }

    public function test_aliases_are_checked(): void
    {
        $admin = $this->as();

        $admin->postJson(self::URL, $this->payload(['aliases' => array_map(fn ($i) => "Nom {$i}", range(1, 11))]))
            ->assertStatus(422)->assertJsonValidationErrors('aliases');
        $admin->postJson(self::URL, $this->payload(['aliases' => ['']]))
            ->assertStatus(422)->assertJsonValidationErrors('aliases.0');
        // Le second des deux est signalé : c'est lui qui répète.
        $admin->postJson(self::URL, $this->payload(['aliases' => ['Les Cracks', 'Les Cracks']]))
            ->assertStatus(422)->assertJsonValidationErrors('aliases.1');
        // Sans casse ni accents, comme le nom (relecture finale).
        $admin->postJson(self::URL, $this->payload(['aliases' => ['Les Cracks', 'les crâcks']]))
            ->assertStatus(422)->assertJsonPath('errors', ['aliases.1' => ['Cet autre nom est répété.']]);
        $admin->postJson(self::URL, $this->payload(['name' => '', 'lat' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'lat']);
    }

    public function test_a_malformed_id_is_a_404(): void
    {
        $admin = $this->as();

        $admin->putJson(self::URL.'/pas-un-uuid', $this->payload())->assertNotFound();
        $admin->deleteJson(self::URL.'/pas-un-uuid')->assertNotFound();
        $admin->deleteJson(self::URL.'/'.fake()->uuid())->assertNotFound();
    }

    public function test_the_journal_traces_only_changed_fields(): void
    {
        $admin = $this->as();
        $id = $admin->postJson(self::URL, $this->payload())->json('places.0.id');
        $admin->putJson(self::URL."/{$id}", $this->payload(['landmark' => 'Fidjrossè plage']))->assertOk();
        $admin->deleteJson(self::URL."/{$id}")->assertOk();

        $events = Activity::query()->where('event', 'like', 'settings.popular_place_%')->orderBy('created_at')->orderBy('id')->get();

        $this->assertSame(['settings.popular_place_added', 'settings.popular_place_updated', 'settings.popular_place_removed'], $events->pluck('event')->all());
        $this->assertSame(['landmark' => ['from' => 'Fidjrossè, Cotonou', 'to' => 'Fidjrossè plage']], $events[1]->properties['changes']);
        $this->assertStringContainsString('Complexe scolaire Les Cracks', $events[2]->description);
    }

    public function test_an_unchanged_update_writes_no_journal_line(): void
    {
        $admin = $this->as();
        $id = $admin->postJson(self::URL, $this->payload())->json('places.0.id');
        $admin->putJson(self::URL."/{$id}", $this->payload())->assertOk();

        $this->assertSame(0, Activity::query()->where('event', 'settings.popular_place_updated')->count());
    }

    public function test_a_write_clears_the_search_cache(): void
    {
        Http::fake(['photon.komoot.io/*' => Http::response(['type' => 'FeatureCollection', 'features' => []])]);
        $this->getJson('/api/v1/public/places?q=crac')->assertJsonCount(0, 'places');

        $this->as()->postJson(self::URL, $this->payload())->assertOk();

        $this->getJson('/api/v1/public/places?q=crac')->assertJsonPath('places.0.label', 'Complexe scolaire Les Cracks');
    }
}

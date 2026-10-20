<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\PreparationStepTemplate;
use App\Models\User;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** La liste par défaut des étapes de préparation, dans les réglages (spec 2026-10-20, §5.1). */
class PreparationStepSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/preparation-steps';

    private function asAdmin(array $permissions = ['manage-business-settings']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function templates(string ...$labels): array
    {
        PreparationStepTemplate::query()->delete();

        return collect($labels)->map(fn ($label, $i) => PreparationStepTemplate::create(['label' => $label, 'position' => $i + 1]))->all();
    }

    public function test_the_list_is_read_in_order(): void
    {
        $this->templates('A', 'B');

        $this->asAdmin()->getJson(self::URL)->assertOk()
            ->assertJsonPath('steps.0.label', 'A')
            ->assertJsonPath('steps.1.label', 'B');
    }

    public function test_put_replaces_the_whole_list_and_keeps_ids(): void
    {
        [$a, $b, $c] = $this->templates('A', 'B', 'C');

        $this->asAdmin()->putJson(self::URL, ['steps' => [
            ['id' => $c->id, 'label' => 'C renommée'],
            ['id' => null, 'label' => 'Nouvelle'],
            ['id' => $a->id, 'label' => 'A'],
        ]])->assertOk()->assertJsonPath('steps.0.id', $c->id);

        $rows = PreparationStepTemplate::orderBy('position')->get();
        $this->assertSame(['C renommée', 'Nouvelle', 'A'], $rows->pluck('label')->all());
        $this->assertSame([1, 2, 3], $rows->pluck('position')->all());
        $this->assertSame($c->id, $rows[0]->id);
        $this->assertNull(PreparationStepTemplate::find($b->id));
    }

    public function test_blank_and_duplicate_labels_are_refused(): void
    {
        $this->templates('A');

        $this->asAdmin()->putJson(self::URL, ['steps' => [['id' => null, 'label' => '   ']]])
            ->assertStatus(422)->assertJsonValidationErrors('steps.0.label');
        $this->asAdmin()->putJson(self::URL, ['steps' => [['id' => null, 'label' => 'Pose GPS'], ['id' => null, 'label' => ' pose gps ']]])
            ->assertStatus(422)->assertJsonValidationErrors('steps.1.label');
        $this->asAdmin()->putJson(self::URL, ['steps' => []])->assertStatus(422);
        $this->assertSame(['A'], PreparationStepTemplate::pluck('label')->all());
    }

    public function test_an_unknown_id_is_refused(): void
    {
        $this->templates('A');

        $this->asAdmin()->putJson(self::URL, ['steps' => [['id' => '00000000-0000-0000-0000-000000000000', 'label' => 'X']]])
            ->assertStatus(422);
    }

    public function test_an_existing_contract_keeps_its_steps(): void
    {
        [$a] = $this->templates('A', 'B');
        $contract = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        PreparationSteps::seedFor($contract);

        $this->asAdmin()->putJson(self::URL, ['steps' => [['id' => $a->id, 'label' => 'Autre']]])->assertOk();

        $this->assertSame(['A', 'B', 'Arrivée du premier agent'], $contract->preparationSteps()->pluck('label')->all());
    }

    public function test_only_manage_business_settings(): void
    {
        $this->asAdmin(['view-contracts'])->getJson(self::URL)->assertForbidden();
    }

    public function test_the_change_is_journaled(): void
    {
        [$a] = $this->templates('A');

        $this->asAdmin()->putJson(self::URL, ['steps' => [['id' => $a->id, 'label' => 'B']]])->assertOk();

        $this->assertSame(1, Activity::where('event', 'settings.preparation_steps_updated')->count());
    }
}

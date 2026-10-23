<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Application\Actions\AttachPreparationProof;
use App\Domains\Fleet\Application\Actions\RemovePreparationStep;
use App\Domains\Fleet\Application\Actions\UpdatePreparationStep;
use App\Domains\Fleet\Domain\PreparationSteps;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Notification;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehicleContractPreparationStep;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Les gestes de l'admin sur les étapes de préparation (spec 2026-10-20, §4.2, §4.4, §5.2). */
class AdminPreparationStepApiTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $contract;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        Storage::fake('local');
        $this->owner = User::factory()->profil(Profil::Owner)->create();
        $vehicle = Vehicle::factory()->create(['owner_id' => $this->owner->id, 'vehicle_number' => '2EV2338 RB']);
        $this->contract = VehicleContract::factory()->forVehicle($vehicle)->create(['status' => 'pending', 'start_date' => null]);
        PreparationSteps::seedFor($this->contract);
    }

    private function asAdmin(array $permissions = ['view-contracts', 'edit-contracts']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function step(string $label = 'Installation du Pocket Wifi'): VehicleContractPreparationStep
    {
        return $this->contract->preparationSteps()->where('label', $label)->firstOrFail();
    }

    private function arrival(): VehicleContractPreparationStep
    {
        return $this->contract->preparationSteps()->where('is_agent_arrival', true)->firstOrFail();
    }

    private function url(?VehicleContractPreparationStep $step = null, string $suffix = ''): string
    {
        return "/api/v1/admin/vehicle-contracts/{$this->contract->id}/preparation-steps".($step ? "/{$step->id}" : '').$suffix;
    }

    private function ownerNotifications()
    {
        return Notification::where('user_id', $this->owner->id)->where('title', 'Préparation de votre véhicule');
    }

    public function test_the_detail_carries_the_steps_and_editability(): void
    {
        $this->asAdmin(['view-contracts'])->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}")->assertOk()
            ->assertJsonPath('preparation_editable', true)
            ->assertJsonCount(10, 'preparation_steps')
            ->assertJsonPath('preparation_steps.9.is_agent_arrival', true);
    }

    /**
     * Deux coches successives, pas simultanées : le test est séquentiel. C'est le passage « à faire »
     * → « faite », lu sous le verrou du contrat puis de l'étape, qui garantit une seule notification
     * quand deux admins cochent en même temps.
     */
    public function test_ticking_notifies_the_owner_once(): void
    {
        $admin = $this->asAdmin();

        $admin->patchJson($this->url($this->step()), ['done_on' => '2026-10-08'])->assertOk()
            ->assertJsonPath('preparation_steps.2.done_on', '2026-10-08');
        $admin->patchJson($this->url($this->step()), ['done_on' => '2026-10-09'])->assertOk();

        $this->assertSame(1, $this->ownerNotifications()->count());
        $this->assertSame('2EV2338 RB : installation du Pocket Wifi, faite le 08/10/2026.', $this->ownerNotifications()->value('message'));
        $this->assertSame('2026-10-09', $this->step()->done_on->toDateString());
    }

    public function test_unticking_clears_the_date_and_the_proof_without_notifying(): void
    {
        $step = $this->step();
        Storage::disk('local')->put("preparation/{$this->contract->id}/p.pdf", '%PDF');
        $step->update(['done_on' => '2026-10-08', 'proof_path' => "preparation/{$this->contract->id}/p.pdf", 'proof_original_name' => 'p.pdf']);

        $this->asAdmin()->patchJson($this->url($step), ['done_on' => null])->assertOk();

        $this->assertNull($step->fresh()->done_on);
        $this->assertNull($step->fresh()->proof_path);
        Storage::disk('local')->assertMissing("preparation/{$this->contract->id}/p.pdf");
        $this->assertSame(0, $this->ownerNotifications()->count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->asAdmin()->patchJson($this->url($this->step()), ['done_on' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('done_on');
    }

    public function test_the_agent_arrival_refuses_every_gesture(): void
    {
        $admin = $this->asAdmin();

        $admin->patchJson($this->url($this->arrival()), ['done_on' => '2026-10-08'])->assertStatus(422);
        $admin->patchJson($this->url($this->arrival()), ['label' => 'Autre'])->assertStatus(422);
        $admin->deleteJson($this->url($this->arrival()))->assertStatus(422);
        $admin->postJson($this->url($this->arrival(), '/proof'), ['file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')])->assertStatus(422);
    }

    public function test_an_active_contract_is_locked(): void
    {
        $this->contract->update(['status' => 'active', 'start_date' => '2026-10-15']);

        $this->asAdmin()->patchJson($this->url($this->step()), ['done_on' => '2026-10-08'])
            ->assertStatus(409)->assertJsonPath('code', 'PREPARATION_LOCKED');
        $this->asAdmin()->getJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}")->assertJsonPath('preparation_editable', false);
    }

    public function test_a_proof_on_a_contract_turned_active_is_refused_and_nothing_is_stored(): void
    {
        $step = $this->step();
        $this->contract->update(['status' => 'active', 'start_date' => '2026-10-15']);

        $this->asAdmin()->postJson($this->url($step, '/proof'), ['file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')])
            ->assertStatus(409);

        $this->assertSame([], Storage::disk('local')->allFiles('preparation'));
    }

    public function test_a_proof_is_attached_replaced_downloaded_and_removed(): void
    {
        $admin = $this->asAdmin();
        $step = $this->step();

        $admin->postJson($this->url($step, '/proof'), ['file' => UploadedFile::fake()->create('facture.pdf', 10, 'application/pdf')])->assertOk()
            ->assertJsonPath('preparation_steps.2.has_proof', true);
        $first = $step->fresh()->proof_path;
        $admin->postJson($this->url($step, '/proof'), ['file' => UploadedFile::fake()->image('photo.jpg')])->assertOk();
        Storage::disk('local')->assertMissing($first);
        $admin->get($this->url($step, '/proof'))->assertOk();

        $admin->deleteJson($this->url($step, '/proof'))->assertOk()->assertJsonPath('preparation_steps.2.has_proof', false);
        $this->assertSame([], Storage::disk('local')->allFiles('preparation'));
        $admin->getJson($this->url($step, '/proof'))->assertNotFound();
    }

    public function test_a_wrong_file_type_or_size_is_refused(): void
    {
        $this->asAdmin()->postJson($this->url($this->step(), '/proof'), ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->asAdmin()->postJson($this->url($this->step(), '/proof'), ['file' => UploadedFile::fake()->create('gros.pdf', 10241, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_an_added_step_goes_just_before_the_arrival(): void
    {
        $this->asAdmin()->postJson($this->url(), ['label' => 'Peinture aux couleurs de l\'agence'])->assertOk()
            ->assertJsonPath('preparation_steps.9.label', 'Peinture aux couleurs de l\'agence')
            ->assertJsonPath('preparation_steps.10.is_agent_arrival', true);
    }

    public function test_a_step_is_renamed(): void
    {
        $this->asAdmin()->patchJson($this->url($this->step()), ['label' => 'Installation du Pocket Wifi 4G'])->assertOk();

        $this->assertSame('Installation du Pocket Wifi 4G', $this->contract->preparationSteps()->where('position', 3)->value('label'));
    }

    public function test_a_done_step_must_be_unticked_before_removal(): void
    {
        $this->step()->update(['done_on' => '2026-10-08']);

        $this->asAdmin()->deleteJson($this->url($this->step()))->assertStatus(422);
    }

    public function test_removing_a_step_deletes_its_proof(): void
    {
        $step = $this->step();
        Storage::disk('local')->put("preparation/{$this->contract->id}/p.pdf", '%PDF');
        $step->update(['proof_path' => "preparation/{$this->contract->id}/p.pdf", 'proof_original_name' => 'p.pdf']);

        $this->asAdmin()->deleteJson($this->url($step))->assertOk()->assertJsonCount(9, 'preparation_steps');

        Storage::disk('local')->assertMissing("preparation/{$this->contract->id}/p.pdf");
    }

    public function test_a_step_of_another_contract_is_404(): void
    {
        $other = VehicleContract::factory()->create(['status' => 'pending', 'start_date' => null]);
        PreparationSteps::seedFor($other);
        $foreign = $other->preparationSteps()->first();

        $this->asAdmin()->patchJson($this->url($foreign), ['done_on' => '2026-10-08'])->assertNotFound();
    }

    public function test_rights(): void
    {
        $this->asAdmin(['view-contracts'])->patchJson($this->url($this->step()), ['done_on' => '2026-10-08'])->assertForbidden();
        $this->asAdmin(['edit-contracts'])->getJson($this->url($this->step(), '/proof'))->assertForbidden();
    }

    public function test_each_gesture_is_journaled(): void
    {
        $admin = $this->asAdmin();
        $step = $this->step();
        $admin->patchJson($this->url($step), ['done_on' => '2026-10-08']);
        $admin->patchJson($this->url($step), ['done_on' => '2026-10-07']);
        $admin->postJson($this->url($step, '/proof'), ['file' => UploadedFile::fake()->create('p.pdf', 10, 'application/pdf')]);
        $admin->deleteJson($this->url($step, '/proof'));
        $admin->patchJson($this->url($step), ['done_on' => null]);
        $admin->patchJson($this->url($step), ['label' => 'Pocket Wifi 4G']);
        $admin->postJson($this->url(), ['label' => 'Peinture']);
        $admin->deleteJson($this->url($step));

        // Même seconde, identifiants uuid : l'ordre n'est pas garanti, l'ensemble l'est.
        $this->assertEqualsCanonicalizing([
            'vehicle.preparation_step_done', 'vehicle.preparation_step_redated', 'vehicle.preparation_proof_attached',
            'vehicle.preparation_proof_removed', 'vehicle.preparation_step_undone', 'vehicle.preparation_step_renamed',
            'vehicle.preparation_step_added', 'vehicle.preparation_step_removed',
        ], Activity::where('event', 'like', 'vehicle.preparation_%')->pluck('event')->all());
    }

    /**
     * Relecture finale : le fichier ne part qu'une fois l'écriture validée. Une transaction
     * annulée garde la preuve, que la base désigne toujours.
     */
    public function test_a_rolled_back_untick_or_removal_keeps_the_proof_file(): void
    {
        $step = $this->step();
        $path = "preparation/{$this->contract->id}/p.pdf";
        Storage::disk('local')->put($path, '%PDF');
        $step->update(['done_on' => '2026-10-08', 'proof_path' => $path, 'proof_original_name' => 'p.pdf']);

        DB::beginTransaction();
        app(UpdatePreparationStep::class)($this->contract, $step, ['done_on' => null], null);
        DB::rollBack();
        Storage::disk('local')->assertExists($path);

        $step->update(['done_on' => null]);
        DB::beginTransaction();
        app(RemovePreparationStep::class)($this->contract, $step->fresh());
        DB::rollBack();
        Storage::disk('local')->assertExists($path);
    }

    public function test_a_replaced_proof_keeps_the_old_file_if_the_write_rolls_back(): void
    {
        $step = $this->step();
        $old = "preparation/{$this->contract->id}/ancien.pdf";
        Storage::disk('local')->put($old, '%PDF');
        $step->update(['proof_path' => $old, 'proof_original_name' => 'ancien.pdf']);

        DB::beginTransaction();
        app(AttachPreparationProof::class)($this->contract, $step, UploadedFile::fake()->create('nouveau.pdf', 10, 'application/pdf'));
        DB::rollBack();

        Storage::disk('local')->assertExists($old);
    }

    public function test_today_is_the_benin_date_between_midnight_and_one(): void
    {
        // 23:30 UTC = 00:30 le 23 au Bénin : le 23 est aujourd'hui, le 24 est futur (mineur M-1).
        Carbon::setTestNow('2026-10-22 23:30:00');
        $admin = $this->asAdmin();

        $admin->patchJson($this->url($this->step()), ['done_on' => '2026-10-23'])->assertOk();
        $admin->patchJson($this->url($this->step()), ['done_on' => '2026-10-24'])->assertStatus(422)->assertJsonValidationErrors('done_on');
        Carbon::setTestNow();
    }

    public function test_removing_a_missing_proof_is_not_journaled(): void
    {
        $this->asAdmin()->deleteJson($this->url($this->step(), '/proof'))->assertOk();

        $this->assertSame(0, Activity::where('event', 'vehicle.preparation_proof_removed')->count());
    }

    public function test_the_notification_keeps_an_acronym_capitalised(): void
    {
        $this->step()->update(['label' => 'GPS posé']);

        $this->asAdmin()->patchJson($this->url($this->step('GPS posé')), ['done_on' => '2026-10-08'])->assertOk();

        $this->assertSame('2EV2338 RB : GPS posé, faite le 08/10/2026.', $this->ownerNotifications()->value('message'));
    }

    public function test_a_malformed_identifier_is_404_not_500(): void
    {
        $admin = $this->asAdmin();

        $admin->patchJson("/api/v1/admin/vehicle-contracts/{$this->contract->id}/preparation-steps/abc", ['done_on' => '2026-10-08'])->assertNotFound();
        $admin->getJson("/api/v1/admin/vehicle-contracts/abc/preparation-steps/abc/proof")->assertNotFound();
    }
}

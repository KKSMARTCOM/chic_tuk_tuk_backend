<?php

namespace Tests\Feature\Fleet;

use App\Domains\Fleet\Domain\PreparationSteps;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Ce que voit le propriétaire de la préparation de son véhicule (spec 2026-10-20, §5.3). */
class OwnerPreparationTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private VehicleContract $contract;

    private function owner(): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-own-vehicles', 'view-own-contracts'] as $permission) {
            $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $owner;
    }

    private function as(User $user): self
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function prepared(User $owner): void
    {
        $this->vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $this->contract = VehicleContract::factory()->forVehicle($this->vehicle)->create(['status' => 'pending', 'start_date' => null]);
        PreparationSteps::seedFor($this->contract);
        $this->contract->preparationSteps()->where('is_agent_arrival', false)->orderBy('position')->limit(4)->get()
            ->each(fn ($step) => $step->update(['done_on' => '2026-10-08']));
    }

    private function proofOnFirstStep(): string
    {
        Storage::fake('local');
        $step = $this->contract->preparationSteps()->first();
        Storage::disk('local')->put("preparation/{$this->contract->id}/p.pdf", '%PDF');
        $step->update(['proof_path' => "preparation/{$this->contract->id}/p.pdf", 'proof_original_name' => 'facture.pdf']);

        return "/api/v1/owner/vehicles/{$this->vehicle->id}/preparation-steps/{$step->id}/proof";
    }

    public function test_a_pending_vehicle_shows_its_steps_without_done_by(): void
    {
        $owner = $this->owner();
        $this->prepared($owner);

        $response = $this->as($owner)->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}")->assertOk()
            ->assertJsonCount(10, 'preparation_steps')
            ->assertJsonPath('preparation_steps.0.done_on', '2026-10-08')
            ->assertJsonPath('preparation_steps.9.is_agent_arrival', true);
        $this->assertArrayNotHasKey('done_by_name', $response->json('preparation_steps.0'));
    }

    public function test_an_active_vehicle_shows_none(): void
    {
        $owner = $this->owner();
        $this->prepared($owner);
        $this->contract->update(['status' => 'active', 'start_date' => '2026-10-15']);

        $this->as($owner)->getJson("/api/v1/owner/vehicles/{$this->vehicle->id}")->assertOk()
            ->assertJsonPath('preparation_steps', null);
    }

    public function test_the_summary_counts_done_steps(): void
    {
        $owner = $this->owner();
        $this->prepared($owner);

        $this->as($owner)->getJson('/api/v1/owner/vehicles')->assertOk()
            ->assertJsonPath('0.preparation_done', 4)
            ->assertJsonPath('0.preparation_total', 10);
    }

    public function test_the_proof_is_downloaded_while_pending(): void
    {
        $owner = $this->owner();
        $this->prepared($owner);

        $this->as($owner)->get($this->proofOnFirstStep())->assertOk();
    }

    public function test_the_proof_is_404_after_service_and_for_another_owner(): void
    {
        $owner = $this->owner();
        $this->prepared($owner);
        $url = $this->proofOnFirstStep();

        $this->as($this->owner())->getJson($url)->assertNotFound();
        Auth::forgetGuards();
        $this->contract->update(['status' => 'active', 'start_date' => '2026-10-15']);
        $this->as($owner)->getJson($url)->assertNotFound();
    }
}

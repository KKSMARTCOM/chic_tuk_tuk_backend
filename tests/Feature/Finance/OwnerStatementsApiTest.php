<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les fiches de rémunération, côté propriétaire (spec 2026-09-30, §5.2) : les siennes
 * seulement, validées seulement, et le PDF « en préparation » tant qu'il n'est pas rangé.
 */
class OwnerStatementsApiTest extends TestCase
{
    use RefreshDatabase;

    private function loginOwner(): array
    {
        $user = User::factory()->profil(Profil::Owner)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        Permission::findOrCreate('view-own-payments', 'web');
        $user->givePermissionTo('view-own-payments');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_the_owner_downloads_their_validated_sheet_and_nobody_else_does(): void
    {
        Storage::fake('local');
        [$owner, $token] = $this->loginOwner();
        $mine = RemunerationStatement::factory()
            ->for(VehicleContract::factory()->forVehicle(Vehicle::factory()->create(['owner_id' => $owner->id])), 'contract')
            ->validated()->create(['pdf_path' => 'statements/2026/FR-2026-10-001.pdf']);
        Storage::disk('local')->put('statements/2026/FR-2026-10-001.pdf', '%PDF-1.7 fiche');
        $other = RemunerationStatement::factory()->validated()->create(['pdf_path' => 'x.pdf']);

        // ⚠️ La 200 sur MA fiche rend le 404 discriminant : une route absente répond 404 aussi.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/owner/statements/{$mine->id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->get("/api/v1/owner/statements/{$other->id}/pdf")
            ->assertNotFound();
    }

    public function test_a_draft_is_invisible_and_a_sheet_in_preparation_says_so(): void
    {
        [$owner, $token] = $this->loginOwner();
        $contract = VehicleContract::factory()->forVehicle(Vehicle::factory()->create(['owner_id' => $owner->id]))->create();
        $draft = RemunerationStatement::factory()->for($contract, 'contract')->create();
        $preparing = RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2026-09-01']);

        $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/owner/statements/{$draft->id}/pdf")->assertNotFound();
        $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/owner/statements/{$preparing->id}/pdf")
            ->assertStatus(409)->assertJsonPath('code', 'STATEMENT_NOT_READY');
    }

    public function test_the_vehicle_lists_its_validated_sheets_only(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2026-10-01', 'number' => 'FR-2026-10-001', 'balance_due' => 31_210]);
        RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2026-11-01']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/statements")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.number', 'FR-2026-10-001')
            ->assertJsonPath('0.month', '2026-10')
            ->assertJsonPath('0.balance_due', 31_210);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles/'.Vehicle::factory()->create()->id.'/statements')
            ->assertNotFound();
    }

    /** @return array{0: string, 1: RemunerationStatement} le jeton du propriétaire et sa fiche, PDF rangé */
    private function ownerWithStatement(): array
    {
        Storage::fake('local');
        config(['remuneration.owner_download_limit' => 3]);
        [$owner, $token] = $this->loginOwner();
        $statement = RemunerationStatement::factory()
            ->for(VehicleContract::factory()->forVehicle(Vehicle::factory()->create(['owner_id' => $owner->id])), 'contract')
            ->validated()->create(['pdf_path' => 'statements/2026/FR-2026-10-001.pdf', 'pdf_generated_at' => now()]);
        Storage::disk('local')->put('statements/2026/FR-2026-10-001.pdf', '%PDF-1.7 fiche');

        return [$token, $statement];
    }

    public function test_three_downloads_then_the_limit(): void
    {
        [$token, $statement] = $this->ownerWithStatement();

        foreach ([2, 1, 0] as $left) {
            $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/owner/statements/{$statement->id}/pdf")->assertOk();
            $this->assertSame($left, $statement->refresh()->ownerDownloadsLeft());
        }
        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/statements/{$statement->id}/pdf")
            ->assertStatus(403)->assertJsonPath('code', 'STATEMENT_DOWNLOAD_LIMIT');
    }

    public function test_an_erased_pdf_answers_410(): void
    {
        [$token, $statement] = $this->ownerWithStatement();
        $statement->update(['pdf_path' => null, 'pdf_purged_at' => now()]);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/statements/{$statement->id}/pdf")
            ->assertStatus(410)->assertJsonPath('code', 'STATEMENT_PDF_EXPIRED');
        $this->assertSame(0, $statement->refresh()->owner_download_count);
    }

    public function test_the_list_tells_the_file_state_and_downloads_left(): void
    {
        [$token, $statement] = $this->ownerWithStatement();
        $statement->update(['owner_download_count' => 1]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$statement->contract->vehicle_id}/statements")
            ->assertOk()->assertJsonPath('0.pdf_state', 'ready')->assertJsonPath('0.downloads_left', 2);
    }

    public function test_an_admin_download_does_not_count(): void
    {
        [, $statement] = $this->ownerWithStatement();
        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $admin->givePermissionTo(Permission::findOrCreate('view-remuneration-statements', 'web'));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")->get("/api/v1/admin/remuneration-statements/{$statement->id}/pdf")->assertOk();

        $this->assertSame(0, $statement->refresh()->owner_download_count);
    }

    public function test_the_limit_message_of_a_reconstituted_statement_does_not_mention_an_email(): void
    {
        [$token, $statement] = $this->ownerWithStatement();
        $statement->update(['owner_download_count' => 3, 'delivery' => 'none']);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/owner/statements/{$statement->id}/pdf")
            ->assertStatus(403)->assertJsonPath('message', 'Limite de téléchargement atteinte.');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$statement->contract->vehicle_id}/statements")
            ->assertJsonPath('0.sent_by_email', false);
    }
}

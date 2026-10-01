<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\RemunerationStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Vider les fiches annulées : le rôle administrateur seul (spec 2026-10-01, §7.1). */
class PurgeCancelledStatementsTest extends TestCase
{
    use RefreshDatabase;

    private function api(bool $adminRole): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach (['view-remuneration-statements', 'edit-remuneration-statements', 'validate-remuneration-statements'] as $p) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        if ($adminRole) {
            $user->assignRole(Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_only_the_admin_role_purges_and_files_go_too(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('statements/2026/FR-2026-03-001.pdf', 'pdf');
        $cancelled = RemunerationStatement::factory()->cancelled()->create(['number' => 'FR-2026-03-001', 'pdf_path' => 'statements/2026/FR-2026-03-001.pdf']);
        $replacement = RemunerationStatement::factory()->create(['replaces_id' => $cancelled->id, 'vehicle_contract_id' => $cancelled->vehicle_contract_id, 'month' => $cancelled->month]);
        $kept = RemunerationStatement::factory()->validated()->create();

        $this->api(adminRole: false)->deleteJson('/api/v1/admin/remuneration-statements/cancelled')
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_ROLE_REQUIRED');
        $this->assertModelExists($cancelled);

        $this->api(adminRole: true)->deleteJson('/api/v1/admin/remuneration-statements/cancelled')
            ->assertOk()->assertJson(['deleted' => 1]);

        $this->assertModelMissing($cancelled);
        $this->assertModelExists($kept);
        $this->assertNull($replacement->refresh()->replaces_id);
        Storage::disk('local')->assertMissing('statements/2026/FR-2026-03-001.pdf');
        $this->assertSame(1, Activity::where('event', 'remuneration_statement.purged')->count());
    }

    public function test_nothing_to_purge_writes_nothing_to_the_journal(): void
    {
        $this->api(adminRole: true)->deleteJson('/api/v1/admin/remuneration-statements/cancelled')
            ->assertOk()->assertJson(['deleted' => 0]);
        $this->assertSame(0, Activity::where('event', 'remuneration_statement.purged')->count());
    }
}

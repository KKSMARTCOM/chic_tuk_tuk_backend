<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Un an de vie pour les PDF, régénérés par l'admin (spec 2026-10-01, §7.2). */
class RemunerationPdfRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2027-10-02 03:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function statementWithPdf(string $generatedAt): RemunerationStatement
    {
        $number = 'FR-2026-03-'.fake()->unique()->numerify('###');
        $path = "statements/2026/{$number}.pdf";
        Storage::disk('local')->put($path, 'pdf');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-02']);

        return RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-03-01', 'number' => $number, 'issued_on' => '2026-04-03', 'pdf_path' => $path,
            'pdf_generated_at' => $generatedAt, 'owner_download_count' => 2,
            'figures' => app(BuildStatementFigures::class)($contract, '2026-03')->toArray(),
        ]);
    }

    private function api(array $permissions = ['view-remuneration-statements', 'validate-remuneration-statements']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_pdfs_older_than_a_year_are_erased_and_the_statement_stays(): void
    {
        $old = $this->statementWithPdf('2026-10-01 09:00:00');
        $recent = $this->statementWithPdf('2026-10-03 09:00:00');
        $oldPath = $old->pdf_path;

        $this->artisan('app:purge-remuneration-pdfs')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame('expired', $old->refresh()->pdfState());
        $this->assertSame('validated', $old->status);
        $this->assertNotNull($old->figures);
        $this->assertSame('ready', $recent->refresh()->pdfState());
    }

    public function test_the_admin_sees_it_unavailable_then_regenerates_it(): void
    {
        $old = $this->statementWithPdf('2026-10-01 09:00:00');
        $this->artisan('app:purge-remuneration-pdfs');
        $api = $this->api();

        $api->getJson("/api/v1/admin/remuneration-statements/{$old->id}/pdf")
            ->assertStatus(410)->assertJsonPath('code', 'STATEMENT_PDF_EXPIRED');

        $api->postJson("/api/v1/admin/remuneration-statements/{$old->id}/pdf")
            ->assertOk()->assertJsonPath('pdf_state', 'ready');

        $old->refresh();
        $this->assertSame('ready', $old->pdfState());
        $this->assertNull($old->pdf_purged_at);
        $this->assertSame(2, $old->owner_download_count);
        $this->assertTrue($old->pdf_generated_at->isSameDay(now()));
        Storage::disk('local')->assertExists($old->pdf_path);
    }

    public function test_regenerating_needs_the_validate_permission(): void
    {
        $old = $this->statementWithPdf('2026-10-01 09:00:00');

        $this->api(['view-remuneration-statements'])->postJson("/api/v1/admin/remuneration-statements/{$old->id}/pdf")->assertForbidden();
    }
}

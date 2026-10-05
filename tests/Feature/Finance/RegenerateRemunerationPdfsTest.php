<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Refaire les PDF des fiches déjà validées, depuis leurs chiffres figés (2026-10-05) : la
 * fiche a perdu cachet, signature et monogramme, et les anciens PDF les portent encore.
 */
class RegenerateRemunerationPdfsTest extends TestCase
{
    use RefreshDatabase;

    private array $figures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        Storage::fake('local');
        Queue::fake();
        $this->figures = app(BuildStatementFigures::class)(VehicleContract::factory()->create(['start_date' => '2026-10-01']), '2026-10')->toArray();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Une fiche dont le PDF rangé contient `ancien`. */
    private function statement(string $number, array $state = []): RemunerationStatement
    {
        $path = "statements/2026/{$number}.pdf";
        Storage::disk('local')->put($path, 'ancien');

        return RemunerationStatement::factory()->validated()->create([
            'number' => $number, 'figures' => $this->figures, 'issued_on' => '2026-11-03',
            'pdf_path' => $path, 'pdf_generated_at' => '2026-11-03 10:00:00', 'owner_download_count' => 2,
            ...$state,
        ]);
    }

    public function test_a_validated_statement_gets_a_new_pdf_and_keeps_its_counters(): void
    {
        $statement = $this->statement('FR-2026-10-001');

        $this->artisan('app:regenerate-remuneration-pdfs')
            ->expectsOutputToContain('1 PDF refait(s)')
            ->assertSuccessful();

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get('statements/2026/FR-2026-10-001.pdf'));
        $statement->refresh();
        $this->assertSame('statements/2026/FR-2026-10-001.pdf', $statement->pdf_path);
        // La date de génération décide de l'effacement au bout d'un an : elle ne bouge pas.
        $this->assertSame('2026-11-03 10:00:00', $statement->pdf_generated_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $statement->owner_download_count);
        // Rien ne repart vers le propriétaire.
        Queue::assertNothingPushed();
    }

    public function test_cancelled_statements_and_purged_pdfs_are_left_alone(): void
    {
        $this->statement('FR-2026-10-002', ['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'Erreur']);
        $purged = RemunerationStatement::factory()->validated()->create([
            'number' => 'FR-2026-10-003', 'figures' => $this->figures, 'pdf_path' => null, 'pdf_purged_at' => now(),
        ]);

        $this->artisan('app:regenerate-remuneration-pdfs')
            ->expectsOutputToContain('0 PDF refait(s)')
            ->assertSuccessful();

        $this->assertSame('ancien', Storage::disk('local')->get('statements/2026/FR-2026-10-002.pdf'));
        $this->assertNull($purged->refresh()->pdf_path);
    }

    public function test_a_dry_run_lists_without_writing(): void
    {
        $this->statement('FR-2026-10-004');

        $this->artisan('app:regenerate-remuneration-pdfs', ['--dry-run' => true])
            ->expectsOutputToContain('FR-2026-10-004')
            ->expectsOutputToContain('1 PDF à refaire')
            ->assertSuccessful();

        $this->assertSame('ancien', Storage::disk('local')->get('statements/2026/FR-2026-10-004.pdf'));
    }
}

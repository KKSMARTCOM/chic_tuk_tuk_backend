<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\GenerateRemunerationStatements;
use App\Models\RemunerationStatement;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateRemunerationStatementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 02:00:00');
        config(['remuneration.first_month' => '2026-10']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function generate(string $month = '2026-10'): int
    {
        return app(GenerateRemunerationStatements::class)($month);
    }

    public function test_a_draft_per_contract_active_during_the_month(): void
    {
        VehicleContract::factory()->create(['start_date' => '2026-05-01']);
        // Terminé en cours de mois : il a encore une fiche pour ses jours d'octobre.
        VehicleContract::factory()->create(['start_date' => '2026-05-01', 'end_date' => '2026-10-10', 'status' => 'completed']);
        // Démarré après le mois, ou terminé avant : pas de fiche.
        VehicleContract::factory()->create(['start_date' => '2026-11-03']);
        VehicleContract::factory()->create(['start_date' => '2026-05-01', 'end_date' => '2026-09-30', 'status' => 'completed']);

        $this->assertSame(2, $this->generate());
        $this->assertSame(2, RemunerationStatement::where('status', 'draft')->count());
    }

    public function test_generating_twice_creates_no_duplicate(): void
    {
        VehicleContract::factory()->create(['start_date' => '2026-05-01']);

        $this->generate();
        $this->assertSame(0, $this->generate());
        $this->assertSame(1, RemunerationStatement::count());
    }

    public function test_nothing_before_the_first_month(): void
    {
        VehicleContract::factory()->create(['start_date' => '2026-05-01']);

        $this->assertSame(0, $this->generate('2026-09'));
    }

    public function test_the_command_defaults_to_the_month_just_ended(): void
    {
        VehicleContract::factory()->create(['start_date' => '2026-05-01']);

        $this->artisan('app:generate-remuneration-statements')->assertSuccessful();

        $this->assertSame('2026-10', RemunerationStatement::first()->monthKey());
    }
}

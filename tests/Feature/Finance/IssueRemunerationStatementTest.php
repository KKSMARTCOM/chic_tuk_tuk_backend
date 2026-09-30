<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Domains\Finance\Application\Mail\RemunerationStatementMail;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IssueRemunerationStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
        config(['remuneration.first_month' => '2026-10']);
        Storage::fake('local');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function validated(): RemunerationStatement
    {
        $owner = User::factory()->create(['email' => 'proprio@example.com']);
        $contract = VehicleContract::factory()
            ->forVehicle(Vehicle::factory()->create(['owner_id' => $owner->id]))
            ->create(['start_date' => '2026-10-01']);

        return RemunerationStatement::factory()->for($contract, 'contract')->validated()->create([
            'month' => '2026-10-01',
            'number' => 'FR-2026-10-001',
            'figures' => app(BuildStatementFigures::class)($contract, '2026-10')->toArray(),
        ]);
    }

    private function issue(RemunerationStatement $statement): void
    {
        app()->call([new IssueRemunerationStatement($statement->id), 'handle']);
    }

    public function test_it_stores_the_pdf_notifies_and_mails_the_owner(): void
    {
        $statement = $this->validated();

        $this->issue($statement);

        $statement->refresh();
        $this->assertSame('statements/2026/FR-2026-10-001.pdf', $statement->pdf_path);
        Storage::disk('local')->assertExists($statement->pdf_path);
        $this->assertNotNull($statement->sent_at);
        $this->assertDatabaseHas('notifications', ['title' => 'Fiche de rémunération d\'octobre 2026']);
        Mail::assertQueued(RemunerationStatementMail::class, fn ($mail) => $mail->hasTo('proprio@example.com'));
    }

    public function test_running_twice_sends_once(): void
    {
        $statement = $this->validated();

        $this->issue($statement);
        $this->issue($statement);

        Mail::assertQueued(RemunerationStatementMail::class, 1);
    }

    public function test_the_mail_elides_the_month_article(): void
    {
        $mail = new RemunerationStatementMail($this->validated());

        $mail->assertSeeInText('fiche de rémunération d\'octobre 2026');
    }

    public function test_the_notification_names_the_vehicle_mid_sentence(): void
    {
        $statement = $this->validated();
        $statement->contract->vehicle->update(['vehicle_number' => '20BJ5689']);

        $this->issue($statement);

        $this->assertDatabaseHas('notifications', [
            'message' => 'Votre fiche de rémunération FR-2026-10-001 pour le véhicule 20BJ5689 est disponible : solde dû 0 FCFA.',
        ]);
    }
}

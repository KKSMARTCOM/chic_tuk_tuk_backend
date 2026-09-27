<?php

namespace App\Console\Commands;

use App\Domains\Booking\Application\Actions\GenerateDueSubscriptionDays;
use Illuminate\Console\Command;

class ProcessRecurringBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-recurring-bookings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée les courses du jour suivant pour les réservations récurrentes';

    public function __construct(private GenerateDueSubscriptionDays $generateDueDays)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = ($this->generateDueDays)();
        $this->info("✓ {$count} réservation(s) récurrente(s) traitée(s).");

        return Command::SUCCESS;
    }
}

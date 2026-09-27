<?php

namespace App\Console\Commands;

use App\Domains\Booking\Application\Actions\ExpireStaleBookings;
use Illuminate\Console\Command;

class ExpireBookings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-bookings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Marque comme expirées les courses dont la date de départ est dépassée';

    public function __construct(private ExpireStaleBookings $expireStaleBookings)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = ($this->expireStaleBookings)();
        $this->info("✓ {$count} réservation(s) marquée(s) comme expirées.");

        return Command::SUCCESS;
    }
}

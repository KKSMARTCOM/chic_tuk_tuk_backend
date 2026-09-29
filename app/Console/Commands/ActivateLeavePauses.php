<?php

namespace App\Console\Commands;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Fleet\Application\Actions\CreateAgentLeavePause;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;

class ActivateLeavePauses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:activate-leave-pauses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Active les pauses agent/véhicule dont la date de début est atteinte';

    /**
     * Execute the console command.
     */
    public function handle(CreateAgentLeavePause $createAgentLeavePause, ActivityJournal $journal): int
    {
        $toActivate = LeaveRequest::where('status', 'ongoing')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereHas('driver', fn ($q) => $q->where('is_available', true))
            ->with('driver.activeDriverContract.vehicle')
            ->get();

        foreach ($toActivate as $leave) {
            $driver = $leave->driver;
            $driver->update(['is_available' => false]);

            $contract = $driver->activeDriverContract;
            if ($contract && $contract->vehicle && ! $contract->vehicle->isOnPause()) {
                $createAgentLeavePause(
                    $contract->vehicle_id,
                    $contract->id,
                    $leave->start_date->toDateString()
                );
            }
        }

        // Une ligne par passage — la commande tourne toutes les deux heures —, et
        // seulement si elle a démarré quelque chose.
        if ($toActivate->count() > 0) {
            $journal->leavesStarted($toActivate->count());
        }

        $this->info($toActivate->count().' pause(s) activée(s).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Domains\Finance\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Après la validation : le PDF, la notification, l'e-mail. Remplie à la tâche 17. */
final class IssueRemunerationStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $statementId) {}

    public function handle(): void {}
}

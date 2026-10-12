<?php

use App\Domains\Finance\Domain\ContractTargets;
use App\Models\VehicleContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Le mois où chaque cumul a été atteint (spec 2026-10-12, §4.1), et la reprise de
 * l'existant (§8.3) : le premier mois de fiche validée dont le cumul atteint le total,
 * sans notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->date('revenue_reached_month')->nullable();
            $table->date('charges_reached_month')->nullable();
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        VehicleContract::query()->whereIn('status', ['active', 'completed'])->each(function (VehicleContract $contract) {
            $reached = ['revenue_reached_month' => null, 'charges_reached_month' => null];
            $statements = DB::table('remuneration_statements')
                ->where('vehicle_contract_id', $contract->id)->where('status', 'validated')
                ->orderBy('month')->get(['month', 'figures']);

            foreach ($statements as $statement) {
                $figures = json_decode($statement->figures ?? '[]', true) ?: [];
                if ($reached['revenue_reached_month'] === null
                    && ContractTargets::isReached((float) ($figures['cumulative_revenue'] ?? 0), ContractTargets::revenue($contract))) {
                    $reached['revenue_reached_month'] = $statement->month;
                }
                if ($reached['charges_reached_month'] === null
                    && ContractTargets::isReached((float) ($figures['cumulative_charges'] ?? 0), ContractTargets::charges($contract))) {
                    $reached['charges_reached_month'] = $statement->month;
                }
            }

            DB::table('vehicle_contracts')->where('id', $contract->id)->update($reached);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_contracts', function (Blueprint $table) {
            $table->dropColumn(['revenue_reached_month', 'charges_reached_month']);
        });
    }
};

<?php

use App\Domains\Fleet\Domain\PreparationSteps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La préparation d'un véhicule (spec 2026-10-20, §3) : la liste par défaut, réglée par
 * l'admin, et les étapes de chaque contrat, copiées à sa création puis ajustées. Les contrats
 * déjà en attente reçoivent la liste par défaut (§3.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_step_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label', 120);
            $table->unsignedInteger('position');
            $table->timestamps();
        });

        Schema::create('vehicle_contract_preparation_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_contract_id')->constrained('vehicle_contracts')->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('position');
            $table->boolean('is_agent_arrival')->default(false);
            $table->date('done_on')->nullable();
            $table->foreignUuid('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_original_name')->nullable();
            $table->string('proof_mime_type')->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->timestamps();
            $table->index(['vehicle_contract_id', 'position']);
        });

        $now = now();
        foreach ([
            'Acquisition du tricycle neuf',
            'Pose de la bâche de protection pluie',
            'Installation du Pocket Wifi',
            'Installation de la sonorisation Bluetooth',
            'Conception et pose des autocollants QR code Wifi',
            'Installation de la tablette Android et de son support',
            'Souscription de l\'assurance (1 an)',
            'Installation du tracking GPS',
            'Formalités administratives',
        ] as $i => $label) {
            DB::table('preparation_step_templates')->insert([
                'id' => (string) Str::uuid(), 'label' => $label, 'position' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        PreparationSteps::backfillPending();
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_contract_preparation_steps');
        Schema::dropIfExists('preparation_step_templates');
    }
};

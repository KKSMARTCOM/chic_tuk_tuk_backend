<?php

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

        // Les contrats déjà en attente (§3.3). Figé ici plutôt que `PreparationSteps::backfillPending()` :
        // une migration qui appelle le code casserait le jour où ses modèles évoluent (mineur M-2).
        $templates = DB::table('preparation_step_templates')->orderBy('position')->pluck('label')->all();
        $pending = DB::table('vehicle_contracts')->where('status', 'pending')->pluck('id');
        foreach ($pending as $contractId) {
            $rows = [];
            foreach ([...$templates, 'Arrivée du premier agent'] as $i => $label) {
                $rows[] = [
                    'id' => (string) Str::uuid(), 'vehicle_contract_id' => $contractId, 'label' => $label,
                    'position' => $i + 1, 'is_agent_arrival' => $i === count($templates),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('vehicle_contract_preparation_steps')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_contract_preparation_steps');
        Schema::dropIfExists('preparation_step_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal d'activité (spatie/laravel-activitylog), 2026-09-29.
 *
 * Les trois migrations publiées par le paquet, fusionnées et corrigées sur un point :
 * `subject` et `causer` y sont des morphs ENTIERS, alors que tout le projet est en UUID —
 * `uuidMorphs` ici. `subject_id` reste une chaîne, pas un `uuid` : un jeton d'appareil a
 * une clé entière, et PostgreSQL refuserait de la comparer à une colonne `uuid`.
 *
 * Datée après les migrations déjà jouées sur staging : le paquet publiait des dates
 * antérieures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->string('event')->nullable()->index();
            $table->string('causer_type')->nullable();
            $table->uuid('causer_id')->nullable();
            $table->index(['causer_type', 'causer_id'], 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};

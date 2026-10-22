<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** La FAQ de l'espace propriétaire (spec 2026-10-22-faq-proprietaire, §2) : rubriques et questions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 120)->unique();
            $table->unsignedInteger('position');
            $table->timestamps();
        });

        Schema::create('faq_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('faq_section_id')->constrained('faq_sections')->cascadeOnDelete();
            $table->string('question', 255);
            // Du Markdown, rendu par le backend comme les communiqués (§3).
            $table->text('answer');
            $table->unsignedInteger('position');
            $table->boolean('is_visible')->default(false);
            $table->timestamps();
            $table->index(['faq_section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entries');
        Schema::dropIfExists('faq_sections');
    }
};

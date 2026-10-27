<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Les lieux populaires, tenus par l'administrateur (spec 2026-10-27-recherche-de-lieux, §3.1). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popular_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Unique sans casse ni accents : vérifié par le contrôleur (§5.1), la liste est petite.
            $table->string('name', 120);
            $table->string('landmark', 120)->nullable();
            $table->json('aliases');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popular_places');
    }
};

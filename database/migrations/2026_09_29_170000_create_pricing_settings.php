<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le prix des courses devient réglable par l'administration : prix de base, prix au
 * kilomètre, et majoration horaire (montant et plage horaire sans majoration).
 *
 * Une seule ligne, amorcée avec les valeurs de l'ex-`Price` : 1 000 FCFA de base, 150 FCFA
 * le kilomètre, +1 000 FCFA hors de la plage 6h–10h (bornes incluses). `BASE_PRICE` (le prix d'une course de 1 km ou moins) et `MINIMUM_PRICE`
 * (le plancher au-delà) valaient tous deux 1 000 : ils fusionnent en un seul prix de base,
 * qui est aussi le minimum d'une course. Deux réglages distincts auraient permis qu'une
 * course de 2 km coûte moins qu'une course d'1 km.
 *
 * Une réservation enregistre son prix : modifier ces valeurs ne touche que les suivantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('base_price');
            $table->unsignedInteger('price_per_km');
            $table->unsignedInteger('time_surcharge');
            $table->unsignedTinyInteger('surcharge_free_start_hour');
            $table->unsignedTinyInteger('surcharge_free_end_hour');
            $table->timestamps();
        });

        DB::table('pricing_settings')->insert([
            'id' => (string) Str::uuid(),
            'base_price' => 1000,
            'price_per_km' => 150,
            'time_surcharge' => 1000,
            'surcharge_free_start_hour' => 6,
            'surcharge_free_end_hour' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};

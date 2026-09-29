<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'il faut pour lister et révoquer les appareils connectés.
 *
 * `personal_access_tokens` retient l'appareil et l'adresse de la connexion, pour que
 * l'utilisateur reconnaisse ses sessions. Les jetons émis avant restent sans : le front
 * les affiche en « Appareil inconnu ».
 *
 * `fcm_tokens` est rattaché à la session qui l'a enregistré, avec suppression en cascade :
 * une session révoquée — révocation, déconnexion, changement de mot de passe — cesse aussi
 * d'être notifiée. Les lignes existantes restent sans session jusqu'au prochain
 * enregistrement de leur appareil.
 *
 * Colonnes facultatives uniquement : l'image précédente continue de fonctionner (rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });

        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};

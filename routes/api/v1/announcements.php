<?php

use App\Domains\Announcement\Presentation\Api\V1\AnnouncementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — les communiqués reçus (2026-10-13)
|--------------------------------------------------------------------------
|
| Même garde que `notifications.php`, pour la même raison : les QUATRE espaces reçoivent
| des communiqués. La portée vient de la ligne destinataire de l'utilisateur connecté.
|
| ⚠️ `unread-count` est déclaré AVANT `{announcement}`, contraint aux uuid : un identifiant
| mal formé répond 404 au lieu de faire échouer la requête dans PostgreSQL.
|
*/

Route::middleware(['token.fresh', 'auth:sanctum'])
    ->prefix('announcements')
    ->name('announcements.')
    ->group(function () {
        Route::get('/', [AnnouncementController::class, 'index'])->name('index');
        Route::get('/unread-count', [AnnouncementController::class, 'unreadCount'])->name('unread-count');
        Route::get('/{announcement}', [AnnouncementController::class, 'show'])->whereUuid('announcement')->name('show');
        Route::get('/{announcement}/attachments/{attachment}', [AnnouncementController::class, 'downloadAttachment'])
            ->whereUuid(['announcement', 'attachment'])->name('attachments.show');
    });

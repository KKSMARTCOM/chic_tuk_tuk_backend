<?php

use Carbon\Carbon;

if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}

if (!function_exists('formatDateFr')) {
    function formatDateFr($date)
    {
        return Carbon::parse($date)
            ->locale('fr')
            ->translatedFormat('d M Y');
    }
}

if (!function_exists('formatTimeFr')) {
    function formatTimeFr($date)
    {
        return Carbon::parse($date)
            ->locale('fr')
            ->translatedFormat('H\hi');
    }
}

if (!function_exists('formatDateTimeFr')) {
    function formatDateTimeFr($date)
    {
        return Carbon::parse($date)
            ->locale('fr')
            ->translatedFormat('d M Y à H\hi');
    }
}

if (!function_exists('bookingStatusBadge')) {
    function bookingStatusBadge(string $status): string
    {
        return match ($status) {
            'pending'     => 'bg-yellow-100 text-yellow-800',
            'confirmed'   => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-indigo-100 text-indigo-800',
            'completed'   => 'bg-green-100 text-green-800',
            'cancelled'   => 'bg-red-100 text-red-800',
            'expired'     => 'bg-gray-100 text-gray-800',
            'missed'      => 'bg-orange-100 text-orange-800',
            default       => 'bg-gray-100 text-gray-800',
        };
    }
}

if (!function_exists('bookingStatusLabel')) {
    function bookingStatusLabel(string $status): string
    {
        return match ($status) {
            'pending'     => 'En attente',
            'confirmed'   => 'Confirmée',
            'in_progress' => 'En cours',
            'completed'   => 'Terminée',
            'cancelled'   => 'Annulée',
            'expired'     => 'Expirée',
            'missed'      => 'Non traitée',
            default       => 'Inconnu',
        };
    }
}

if (!function_exists('vehiculeType')) {
    function vehiculeType(?string $type): string
    {
        return match ($type) {
            'tricycle'     => 'Tricycle',
            'moto'   => 'Moto',
            'car' => 'Voiture',
            default       => 'Inconnu',
        };
    }
}

if (!function_exists('calculateEndDate')) {
    function calculateEndDate(string $startDate, int $days, string $weekDays): Carbon
    {
        // Jours autorisés selon le pattern
        $allowedDays = match ($weekDays) {
            'lun_ven' => [1, 2, 3, 4, 5],       // Lun=1 ... Ven=5
            'lun_sam' => [1, 2, 3, 4, 5, 6],    // + Sam=6
            'lun_dim' => [1, 2, 3, 4, 5, 6, 0], // + Dim=0
            default   => [1, 2, 3, 4, 5, 6, 0], // tous les jours
        };

        $current   = Carbon::parse($startDate);
        $remaining = $days;

        // J1 compte si son jour est autorisé
        if (!in_array($current->dayOfWeek, $allowedDays)) {
            throw new \Exception(
                'La date de départ choisie ne correspond pas aux jours de circulation sélectionnés.'
            );
        }

        // Compter les jours ouvrés jusqu'à épuiser $days
        while ($remaining > 1) {
            $current->addDay();

            if (in_array($current->dayOfWeek, $allowedDays)) {
                $remaining--;
            }
        }

        return $current; // date du dernier jour
    }
}

if (!function_exists('getNextAllowedDay')) {
    function getNextAllowedDay(Carbon $from, string $weekDays): ?Carbon
    {
        $allowedDays = match ($weekDays) {
            'lun_ven' => [1, 2, 3, 4, 5],
            'lun_sam' => [1, 2, 3, 4, 5, 6],
            'lun_dim' => [1, 2, 3, 4, 5, 6, 0],
            default   => [1, 2, 3, 4, 5, 6, 0],
        };

        $next = $from->copy()->addDay();

        // Avancer jusqu'au prochain jour autorisé (max 7 jours pour éviter boucle infinie)
        $attempts = 0;
        while (!in_array($next->dayOfWeek, $allowedDays) && $attempts < 7) {
            $next->addDay();
            $attempts++;
        }

        return $attempts < 7 ? $next : null;
    }
}

if (!function_exists('formatAmount')) {
    function formatAmount($n)
    {
        return number_format($n, 0, ',', ' ') . ' FCFA';
    }
}

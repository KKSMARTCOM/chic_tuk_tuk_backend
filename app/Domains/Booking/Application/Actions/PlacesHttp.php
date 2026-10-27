<?php

namespace App\Domains\Booking\Application\Actions;

use Illuminate\Support\Facades\Http;

/** L'appel commun à Photon et à Nominatim : 3 secondes, un `User-Agent` qui nous nomme. */
final class PlacesHttp
{
    private const TIMEOUT_SECONDS = 3;

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     */
    public static function getJson(string $url, array $query, string $engine): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent('ChicTukTuk/1.0 (+https://chictuktuk.com)')
                ->acceptJson()
                ->get($url, $query);
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$engine} injoignable : ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("{$engine} : statut {$response->status()}.");
        }

        $data = json_decode($response->body(), true);
        if (! is_array($data)) {
            throw new \RuntimeException("{$engine} : réponse illisible.");
        }

        return $data;
    }
}

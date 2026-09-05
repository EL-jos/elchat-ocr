<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MercureService
{
    protected $mercureUrl;
    protected $jwtKey;

    public function __construct()
    {
        $this->mercureUrl = env('MERCURE_URL');
        $this->jwtKey = env('JWT_KEY');
    }

    public function post(string $topic, array $data, ?string $view = null)
    {
        // Si une vue est fournie, on la rend
        $content = $view ? view($view, $data)->render() : json_encode($data);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->jwtKey,
        ])
            ->timeout(10)                  // timeout total
            ->connectTimeout(5)            // timeout connexion
            ->withOptions([
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4], // forcer IPv4
            ])
            ->asForm()                      // envoie en application/x-www-form-urlencoded
            ->post($this->mercureUrl, [
                'topic' => $topic,
                'data'  => $content,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Mercure error: '.$response->status().' '.$response->body());
        }

        return $response->body();

    }
}
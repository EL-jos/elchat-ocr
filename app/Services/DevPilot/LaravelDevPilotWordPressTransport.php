<?php

namespace App\Services\DevPilot;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Laravel HTTP adapter so tests can replace requests with Http::fake(). */
final class LaravelDevPilotWordPressTransport implements DevPilotWordPressTransport
{
    public function get(string $url, string $username, string $password, array $options): Response
    {
        $request = Http::withOptions($options);
        if ($username !== '' || $password !== '') $request = $request->withBasicAuth($username, $password);

        return $request->get($url);
    }

    public function request(string $method, string $url, string $username, string $password, array $options, ?array $jsonBody = null): Response
    {
        $request = Http::withOptions($options);
        if ($username !== '' || $password !== '') $request = $request->withBasicAuth($username, $password);
        if ($jsonBody !== null) {
            $request = $request->withBody(json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json');
        }

        return $request->send(strtoupper($method), $url);
    }
}

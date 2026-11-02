<?php

namespace App\Services\DevPilot;

use Illuminate\Http\Client\Response;

/** Injectable transport; callers provide only server-constructed REST routes and methods. */
interface DevPilotWordPressTransport
{
    /** @param array<string,mixed> $options */
    public function get(string $url, string $username, string $password, array $options): Response;

    /** @param array<string,mixed> $options @param array<string,mixed>|null $jsonBody */
    public function request(string $method, string $url, string $username, string $password, array $options, ?array $jsonBody = null): Response;
}

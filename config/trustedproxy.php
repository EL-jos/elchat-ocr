<?php

return [
    /*
    | Laravel otherwise sees the address of the local reverse proxy as the
    | visitor address. Trust only private/loopback proxy networks by default;
    | deployments using a public load balancer can override this list with
    | TRUSTED_PROXIES (or "*" when the application is not directly exposed).
    */
    'proxies' => env(
        'TRUSTED_PROXIES',
        '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10'
    ),
];

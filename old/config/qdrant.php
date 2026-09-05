<?php

return [
    /*'host' => env('QDRANT_HOST', 'http://127.0.0.1'),
    'port' => env('QDRANT_PORT', 6333),
    'collection' => env('QDRANT_COLLECTION', 'chunks'),
    'url' => env('QDRANT_HOST', 'http://127.0.0.1') . ":" . env('QDRANT_PORT', 6333),*/
    'collection' => env('QDRANT_COLLECTION', 'chunks'),
    'timeout' => 10,
    'url' => 'http://127.0.0.1:6333',
    'api_key' => env('QDRANT_API_KEY'),
];

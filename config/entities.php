<?php

use App\Services\transformers\DocumentEntityTransformer;
use App\Services\transformers\ImageEntityTransformer;
use App\Services\transformers\PageEntityTransformer;
use App\Services\transformers\WooCommerceEntityTransformer;

return [
    'transformers' => [
        WooCommerceEntityTransformer::class,
        PageEntityTransformer::class,
        DocumentEntityTransformer::class,
        ImageEntityTransformer::class,
    ],
    'aggregation' => [

        'woocommerce' => [
            'important_fields' => [
                'image_url',
                'product_url',
                'price',
                'discount_price',
                'product_name',
                'product_reference',
            ],
        ],

    ],
    'relevance' => [

        'threshold' => 0.55,

        'weights' => [
            'semantic' => 0.5,   // 0.7 → 0.5
            'keyword' => 0.2,    // inchangé, mais enfin actif
            'entity_bonus' => 0.3, // 0.1 → 0.3
        ],

        'fields' => [
            'title',
            'description',
        ],

        'max_entities' => 4,

    ],
];

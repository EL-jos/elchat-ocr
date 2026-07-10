<?php

return [

    // Modèle multimodal OpenRouter utilisé pour l'OCR / la compréhension d'image
    'model' => env('OPENROUTER_VISION_MODEL', 'qwen/qwen3.6-plus'),

    'openrouter_api_key' => env('OPENROUTER_API_KEY'),

    // Nombre max d'images traitées par page (contrôle du coût)
    'max_images_per_page' => (int) env('VISION_MAX_IMAGES_PER_PAGE', 15),

    // Nombre max d'images embarquées traitées par document (PDF/DOCX/XLSX)
    'max_images_per_document' => (int) env('VISION_MAX_IMAGES_PER_DOCUMENT', 30),

    // Taille max d'une image téléchargée (octets) - au-delà, on l'ignore
    'max_image_bytes' => (int) env('VISION_MAX_IMAGE_BYTES', 8 * 1024 * 1024), // 8 Mo

    // Dimensions minimales pour considérer une image comme "informative"
    // (en dessous : icône, puce, spacer, avatar, etc. -> ignorée)
    'min_width' => (int) env('VISION_MIN_WIDTH', 100),
    'min_height' => (int) env('VISION_MIN_HEIGHT', 100),

    // Mots-clés de nom de fichier à exclure d'office (logos, icônes, tracking...)
    'filename_blacklist' => [
        'icon', 'logo', 'sprite', 'avatar', 'favicon', 'pixel',
        'spacer', 'badge', 'emoji', 'placeholder', 'blank', 'loader',
        'spinner', 'tracking', 'pixel-track',
    ],

    // Extensions traitées comme des images (utilisé par IndexService::indexDocument
    // pour détecter qu'un document uploadé par l'admin doit passer par le pipeline
    // vision/OCR plutôt que par l'extraction de texte classique)
    'image_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif'],

    // Queue dédiée pour ne pas concurrencer le crawl / les autres jobs
    'queue' => env('VISION_QUEUE', 'vision'),

    // Timeout HTTP (secondes) pour le téléchargement d'une image
    'download_timeout' => (int) env('VISION_DOWNLOAD_TIMEOUT', 20),

    // Timeout HTTP (secondes) pour l'appel au vision model
    'call_timeout' => (int) env('VISION_CALL_TIMEOUT', 45),

    'max_retries' => (int) env('VISION_MAX_RETRIES', 4),
];

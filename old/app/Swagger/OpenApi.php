<?php

namespace App\Swagger;

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *   @OA\Info(
 *     title="Recruitment Test API",
 *     version="1.0.0",
 *     description="API de gestion des tests techniques avec authentification JWT"
 *   ),
 *   @OA\Server(
 *      url=L5_SWAGGER_CONST_HOST,
 *      description="Serveur principal"
 *  ),
 * )
 *
 * @OA\Components(
 *   @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Entrez le token sous la forme: Bearer {token}"
 *   )
 * )
 *
 * @OA\SecurityRequirement(
 *   securityScheme="bearerAuth"
 * )
 */
class OpenApi {}

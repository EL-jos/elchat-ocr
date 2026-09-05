<?php

namespace App\Interfaces;

interface EntityTransformer
{
    public function supports(array $chunk): bool;

    public function transform(array $chunk): ?array;
}

<?php

namespace App\Services\queryAnalyzer;

class QueryPlan
{
    public string $cleanQuery;
    public array $searchQueries = [];
    public array $subQueries = [];
    public array $entities = [];
    public string $intent;
    public string $queryType;
    public bool $needsConversationContext = false;
    public array $filters = [];
    public int $topK = 8;
    public string $searchStrategy = "single";
    public  array $constraints = [];
}

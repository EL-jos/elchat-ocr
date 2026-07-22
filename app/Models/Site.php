<?php

namespace App\Models;

use App\Models\Mcp\McpSiteConnector;
use App\Models\Social\SocialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends BaseModel
{
    protected $casts = [
        'exclude_pages' => 'array',
        'include_pages' => 'array', // ✅ nouveau
    ];
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
    public function unansweredQuestions(): HasMany
    {
        return $this->hasMany(UnansweredQuestion::class);
    }
    public function type(): BelongsTo{
        return $this->belongsTo(TypeSite::class, 'type_site_id');
    }
    public function documents(){
        return $this->morphMany(Document::class, 'documentable');
    }
    public function users(){
        return $this->belongsToMany(User::class)
            ->withPivot(['first_seen_at', 'last_seen_at']);
    }
    public function settings(){
        return $this->hasOne(WidgetSetting::class);
    }
    public function productIport(){
        return $this->hasMany(ProductImport::class);
    }
    public function knowledgeQualityScore(){
        return $this->hasOne(KnowledgeQualityScore::class);
    }
    public function ctas(): HasMany
    {
        return $this->hasMany(ChatbotCta::class, 'site_id', 'id')
            ->orderBy('position');
    }
    public function chunks(){
        return $this->hasMany(Chunk::class);
    }
    public function chatFormSubmissions(){
        return $this->hasMany(ChatFormSubmission::class);
    }
    public function ragEvaluationRuns(): HasMany {
        return $this->hasMany(RagEvaluationRun::class, 'site_id');
    }
    public function ragEvaluationQueries(): HasMany {
        return $this->hasMany(RagEvaluationQuery::class, 'site_id');
    }
    public function ragEvaluationResults(): HasMany {
        return $this->hasMany(RagEvaluationResult::class, 'site_id');
    }

    public function socialAccounts(): HasMany {
        return $this->hasMany(SocialAccount::class, 'site_id');
    }
    public function mcpSiteConnectors(){
        return $this->hasMany(McpSiteConnector::class);
    }
}

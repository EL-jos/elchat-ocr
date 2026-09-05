<?php

namespace App\Models\Sales;

use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prospect extends Model
{
    use HasUuids;

    protected $table = 'sales_prospects';

    protected $fillable = [
        'site_id', 'campaign_id', 'conversation_id', 'name', 'company', 'website', 'domain',
        'prospecting_run_id', 'email', 'phone', 'source', 'location', 'sector', 'score', 'score_reasons',
        'address', 'contact_person', 'other_contact',
        'enrichment_data', 'qualification_data', 'normalized_name', 'normalized_phone',
        'status', 'email_status', 'crm_ref', 'crm_sync_status', 'crm_sync_error', 'last_activity_at',
    ];

    protected $casts = [
        'score_reasons' => 'array', 'enrichment_data' => 'array', 'qualification_data' => 'array',
        'crm_ref' => 'array', 'last_activity_at' => 'datetime',
    ];

    public const DO_NOT_CONTACT = 'do_not_contact';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ProspectingCampaign::class, 'campaign_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ProspectMessage::class, 'prospect_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ProspectEvidence::class, 'prospect_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProspectingRun::class, 'prospecting_run_id');
    }

    public function isContactable(): bool
    {
        return $this->status !== self::DO_NOT_CONTACT;
    }

    public function touchActivity(): void
    {
        $this->update(['last_activity_at' => now()]);
    }
}

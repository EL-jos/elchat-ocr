<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteSecurityRemediationProposal extends BaseModel
{
    protected $table = 'website_security_remediation_proposals';

    protected $fillable = [
        'account_id', 'site_id', 'audit_id', 'finding_id', 'finding_fingerprint', 'status',
        'connector_slug', 'tool_name', 'params', 'proposed_by', 'reviewed_by', 'reviewed_at',
        'conversation_id', 'result', 'error_code', 'executed_at',
    ];

    protected $casts = [
        'params' => 'array', 'result' => 'array', 'reviewed_at' => 'datetime', 'executed_at' => 'datetime',
    ];

    protected $hidden = ['account_id'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WebsiteSecurityAudit::class, 'audit_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

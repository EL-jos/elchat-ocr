<?php

namespace App\Models\Mcp;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;

class McpSiteConnector extends Model
{
    protected $fillable = ['site_id', 'mcp_connector_id', 'credentials_encrypted', 'status', 'connected_at', 'last_used_at', 'last_error_at', 'last_error_message', 'settings', 'provider_tenant_id', 'provider_principal_id', 'provider_principal_upn', 'granted_scopes'];

    protected $casts = [
        'settings' => 'array',
        'connected_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_error_at' => 'datetime',
        'granted_scopes' => 'array',
    ];

    // credentials_encrypted n'est JAMAIS castée en array ni exposée : accès
    // exclusivement via CredentialVault. On l'exclut explicitement des
    // sérialisations accidentelles (toArray/toJson).
    protected $hidden = ['credentials_encrypted'];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function mcpConnector()
    {
        return $this->belongsTo(McpConnector::class);
    }
}

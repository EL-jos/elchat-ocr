<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\MCP\Agent\AgentSkillResolver;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpAgent;
use App\Models\Site;
use Illuminate\Http\Request;

class MCPAgentController extends Controller
{
    public function __construct(private readonly AgentSkillResolver $resolver)
    {
    }

    public function skillsCatalog(Request $request, Site $site)
    {
        return response()->json(['data' => $this->resolver->catalogFor($site)]);
    }

    public function index(Request $request, Site $site)
    {
        return response()->json(['data' => McpAgent::where('site_id', $site->id)->orderByDesc('created_at')->get()]);
    }

    public function store(Request $request, Site $site)
    {
        $agent = McpAgent::create(['site_id' => $site->id, ...$this->validated($request)]);
        return response()->json(['data' => $agent]);
    }

    public function update(Request $request, Site $site, McpAgent $agent)
    {
        $agent->update($this->validated($request));
        return response()->json(['data' => $agent]);
    }

    public function destroy(Request $request, Site $site, McpAgent $agent)
    {
        $agent->delete();
        return response()->json(['status' => 'deleted']);
    }

    /** 🆕 Plusieurs agents peuvent désormais être actifs en même temps — plus d'exclusivité mutuelle. */
    public function publish(Request $request, Site $site, McpAgent $agent)
    {
        $agent->update(['is_active' => true]);
        return response()->json(['data' => $agent]);
    }

    public function unpublish(Request $request, Site $site, McpAgent $agent)
    {
        $agent->update(['is_active' => false]);
        return response()->json(['data' => $agent]);
    }

    /** 🆕 Agent utilisé en repli si le superviseur ne trouve aucune correspondance claire — un seul par site. */
    public function setAsFallback(Request $request, Site $site, McpAgent $agent)
    {
        McpAgent::where('site_id', $site->id)->update(['is_default' => false]);
        $agent->update(['is_default' => true, 'is_active' => true]);
        return response()->json(['data' => $agent]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'objective' => ['nullable', 'string'],
            'tone' => ['required', 'in:professional,friendly,concise,enthusiastic,custom'],
            'custom_tone_instructions' => ['nullable', 'string'],
            'skills' => ['array'],
            'is_active' => ['boolean'],
        ]);
    }
}

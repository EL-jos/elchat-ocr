<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MCPWorkflowController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $workflows = McpWorkflow::where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->orderByDesc('site_id')
            ->get();

        return response()->json(['data' => $workflows]);
    }

    public function store(Request $request, Site $site)
    {
        $workflow = McpWorkflow::create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            ...$this->validated($request),
        ]);

        return response()->json(['data' => $workflow]);
    }

    /** Modifier une recette GLOBALE crée une copie propre au site, plutôt que d'altérer le modèle partagé par tous les autres sites. */
    public function update(Request $request, Site $site, McpWorkflow $workflow)
    {
        if ($workflow->site_id !== $site->id) {
            $copy = McpWorkflow::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id, 'slug' => $workflow->slug,
                ...$this->validated($request),
            ]);
            return response()->json(['data' => $copy]);
        }

        $workflow->update($this->validated($request));
        return response()->json(['data' => $workflow]);
    }

    public function destroy(Request $request, Site $site, McpWorkflow $workflow)
    {
        if ($workflow->site_id !== $site->id) {
            return response()->json(['message' => "Impossible de supprimer une recette globale — désactivez-la plutôt."], 403);
        }
        $workflow->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_description' => ['required', 'string'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.capability' => ['required', 'string'],
            'steps.*.label' => ['required', 'string'],
            'steps.*.optional' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}

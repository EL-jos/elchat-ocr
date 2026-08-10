<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use App\Services\mcp\WorkflowProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MCPWorkflowController extends Controller
{
    public function __construct(private readonly WorkflowProvisioningService $provisioning) {} // 🆕

    public function index(Request $request, Site $site)
    {
        $workflows = McpWorkflow::where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->orderByDesc('site_id')
            ->get();

        return response()->json(['data' => $workflows]);
    }

    public function store(Request $request, Site $site)
    {
        $validated = $this->validated($request);

        $workflow = McpWorkflow::create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'slug' => Str::slug($validated['name']) . '-' . Str::random(6), // 🆕 unique et jamais vide
            ...$validated,
        ]);

        return response()->json(['data' => $workflow]);
    }

    /** Modifier une recette GLOBALE crée une copie propre au site, plutôt que d'altérer le modèle partagé par tous les autres sites. */
    public function update(Request $request, Site $site, McpWorkflow $workflow)
    {
        if ($workflow->site_id !== $site->id) {
            $copy = McpWorkflow::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id,
                'slug' => $workflow->slug . '-' . Str::random(6), // 🆕 évite un doublon de slug avec l'original
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

    /** 🆕 État des dépendances du workflow pour ce site, sans rien modifier. */
    public function dependencies(Request $request, Site $site, McpWorkflow $workflow)
    {
        return response()->json(['data' => $this->provisioning->checkDependencies($site, $workflow)]);
    }

    /** 🆕 Installe le workflow (copie locale si recette globale) + provisionne les capacités disponibles. */
    public function install(Request $request, Site $site, McpWorkflow $workflow)
    {
        $result = $this->provisioning->install($site, $workflow);
        return response()->json(['data' => $result]);
    }
}

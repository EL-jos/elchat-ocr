<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\AIRole;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use App\Models\WidgetSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WidgetSettingController extends Controller
{
    /**
     * Get widget settings for a site
     * (auto-create if not exists)
     */
    public function index(Request $request, Site $site)
    {
        $user = $request->user();

        // 🔐 Sécurité : propriétaire du site
        abort_if($site->account_id !== $user->ownedAccount->id, 403);

        // 🔹 Auto-création si absent
        /**
         * @var WidgetSetting $widgetSetting
         */
        $widgetSetting = $site->settings()->first();

        if (!$widgetSetting) {
            // 🔹 Détermination automatique du rôle IA selon le type de site
            $typeId = $site->type->id;

            $aiRole = match (true) {
                in_array($typeId, [
                    '22222222-2222-4222-8222-222222222222',
                    '44444444-4444-4444-8444-444444444444',
                    '55555555-5555-4555-8555-555555555555']) => AIRole::where('name', 'Commercial')->first(),
                in_array($typeId, [
                    '66666666-6666-4666-8666-666666666666',
                    'cccccccc-cccc-4ccc-8ccc-cccccccccccc']) => AIRole::where('name', 'Support')->first(),
                in_array($typeId, [
                    '77777777-7777-4777-8777-777777777777',
                    '14141414-1414-4141-8141-141414141414']) => AIRole::where('name', 'Professeur')->first(),
                in_array($typeId, [
                    '99999999-9999-4999-8999-999999999999',
                    '33333333-3333-4333-8333-333333333333']) => AIRole::where('name', 'Journaliste')->first(),
                default => AIRole::where('is_default', true)->first(), // fallback Neutre
            };

            $widgetSetting = WidgetSetting::create([
                'id' => Str::uuid(),
                'site_id' => $site->id,
                'ai_role_id' => $aiRole->id,
            ]);
        }

        $settings = $widgetSetting->refresh();
        return response()->json([
            'data' => $settings->load('aiRole'),
        ]);
    }

    /**
     * Store (create) widget settings (rarement appelé si index auto-create)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'site_id' => 'required|uuid|exists:sites,id',
        ]);

        $site = Site::findOrFail($validated['site_id']);
        $user = $request->user();

        abort_if($site->account_id !== $user->ownedAccount->id, 403);

        // ❌ Un seul widget setting par site
        if ($site->widgetSetting) {
            return response()->json([
                'message' => 'Widget settings already exist for this site',
            ], 409);
        }

        // 🔹 Détermination automatique du rôle IA selon le type de site
        $typeId = $site->type->id;

        $aiRole = match (true) {
            in_array($typeId, [
                '22222222-2222-4222-8222-222222222222',
                '44444444-4444-4444-8444-444444444444',
                '55555555-5555-4555-8555-555555555555']) => AIRole::where('name', 'Commercial')->first(),
            in_array($typeId, [
                '66666666-6666-4666-8666-666666666666',
                'cccccccc-cccc-4ccc-8ccc-cccccccccccc']) => AIRole::where('name', 'Support')->first(),
            in_array($typeId, [
                '77777777-7777-4777-8777-777777777777',
                '14141414-1414-4141-8141-141414141414']) => AIRole::where('name', 'Professeur')->first(),
            in_array($typeId, [
                '99999999-9999-4999-8999-999999999999',
                '33333333-3333-4333-8333-333333333333']) => AIRole::where('name', 'Journaliste')->first(),
            default => AIRole::where('is_default', true)->first(), // fallback Neutre
        };

        $widgetSetting = WidgetSetting::create([
            'id' => Str::uuid(),
            'site_id' => $site->id,
            'ai_role_id' => $aiRole->id,
        ]);

        return response()->json([
            'message' => 'Widget settings created',
            'data' => $widgetSetting,
        ], 201);
    }

    /**
     * Show widget settings
     */
    public function show(Request $request, WidgetSetting $widgetSetting)
    {
        $user = $request->user();

        abort_if(
            $widgetSetting->site->account_id !== $user->ownedAccount->id,
            403
        );

        return response()->json([
            'data' => $widgetSetting,
        ]);
    }

    /**
     * Update widget settings
     */
    public function update(Request $request, WidgetSetting $widgetSetting)
    {
        $user = $request->user();

        abort_if(
            $widgetSetting->site->account_id !== $user->ownedAccount->id,
            403
        );

        $validated = $request->validate([
            // Button
            'button_text' => 'nullable|string|max:50',
            'button_background' => 'nullable|string|max:20',
            'button_color' => 'nullable|string|max:20',
            'button_position' => 'nullable|in:bottom-right,bottom-left,top-right,top-left',
            'button_offset_x' => 'nullable|string|max:10',
            'button_offset_y' => 'nullable|string|max:10',

            // Theme
            'theme_primary' => 'nullable|string|max:20',
            'theme_secondary' => 'nullable|string|max:20',
            'theme_background' => 'nullable|string|max:20',
            'theme_color' => 'nullable|string|max:20',

            // Messages
            'message_user_background' => 'nullable|string|max:20',
            'message_user_color' => 'nullable|string|max:20',
            'message_bot_background' => 'nullable|string|max:20',
            'message_bot_color' => 'nullable|string|max:20',

            // Widget / AI
            'widget_enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'bot_name' => 'nullable|string|max:50',
            'bot_language' => 'nullable|string|max:5',
            'welcome_message' => 'nullable|string|max:255',
            'input_placeholder' => 'nullable|string|max:100',

            'ai_temperature' => 'nullable|numeric|min:0|max:1',
            'ai_max_tokens' => 'nullable|integer|min:50|max:4000',
            'min_similarity_score' => 'nullable|numeric|min:0|max:1',
            'fallback_message' => 'nullable|string|max:255',
            'ai_role_id' => 'nullable|string|exists:ai_roles,id',
            'require_authentication' => 'nullable|boolean',

            // AI Engagement
            'ai_engagement_enabled' => 'nullable|boolean',
            'ai_engagement_widget_behavior' => 'nullable|in:notification_only,auto_open',
            'ai_engagement_agent_id' => 'nullable|uuid',
            'ai_engagement_workflow_id' => 'nullable|uuid',
            'ai_engagement_max_per_session' => 'nullable|integer|min:1|max:10',
            'ai_engagement_max_per_visitor' => 'nullable|integer|min:1|max:20',
            'ai_engagement_visitor_window_seconds' => 'nullable|integer|min:3600|max:2592000',
            'ai_engagement_cooldown_seconds' => 'nullable|integer|min:0|max:2592000',
            'ai_engagement_close_cooldown_seconds' => 'nullable|integer|min:0|max:2592000',
            'ai_engagement_refusal_cooldown_seconds' => 'nullable|integer|min:0|max:31536000',
            'ai_engagement_min_session_seconds' => 'nullable|integer|min:5|max:3600',
            'ai_engagement_min_pages' => 'nullable|integer|min:1|max:20',
            'ai_engagement_min_score' => 'nullable|integer|min:1|max:100',
            'ai_engagement_strategies' => 'nullable|array',
            'ai_engagement_strategies.*' => 'in:assistance,targeted_question,navigation,sales,lead_generation,booking,cta,support',
        ]);

        if (!empty($validated['ai_engagement_agent_id'])) {
            abort_unless(McpAgent::query()->where('site_id', $widgetSetting->site_id)->whereKey($validated['ai_engagement_agent_id'])->exists(), 422, 'L’agent AI Engagement doit appartenir à ce site.');
        }
        if (!empty($validated['ai_engagement_workflow_id'])) {
            abort_unless(McpWorkflow::query()->where('site_id', $widgetSetting->site_id)->whereKey($validated['ai_engagement_workflow_id'])->exists(), 422, 'Le workflow AI Engagement doit appartenir à ce site.');
        }

        $widgetSetting->update($validated);

        return response()->json([
            'message' => 'Widget settings updated',
            'data' => $widgetSetting->fresh(),
        ]);
    }

    /**
     * Delete widget settings (rare)
     */
    public function destroy(Request $request, WidgetSetting $widgetSetting)
    {
        $user = $request->user();

        abort_if(
            $widgetSetting->site->account_id !== $user->ownedAccount->id,
            403
        );

        $widgetSetting->delete();

        return response()->json([
            'message' => 'Widget settings deleted',
        ]);
    }
}

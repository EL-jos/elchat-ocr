<?php

ini_set('max_execution_time', 0);
set_time_limit(0);

use App\Http\Controllers\api\v1\AIRoleController;
use App\Http\Controllers\api\v1\AnalyticsController;
use App\Http\Controllers\api\v1\AuthController;
use App\Http\Controllers\api\v1\ChatController;
use App\Http\Controllers\api\v1\ChunkController;
use App\Http\Controllers\api\v1\ConversationController;
use App\Http\Controllers\api\v1\DashboardController;
use App\Http\Controllers\api\v1\DocumentController;
use App\Http\Controllers\api\v1\ManualContentController;
use App\Http\Controllers\api\v1\PageController;
use App\Http\Controllers\api\v1\ProactiveWidgetController;
use App\Http\Controllers\api\v1\ResourceEventAnalyticsController;
use App\Http\Controllers\api\v1\ResourceEventController;
use App\Http\Controllers\api\v1\SiteController;
use App\Http\Controllers\api\v1\SitemapController;
use App\Http\Controllers\api\v1\TypeSiteController;
use App\Http\Controllers\api\v1\UserController;
use App\Http\Controllers\api\v1\VisitorIntelligenceController;
use App\Http\Controllers\api\v1\VisitorIntelligenceIngestionController;
use App\Http\Controllers\api\v1\WidgetSettingController;
use App\Http\Controllers\api\v1\WidgetVisitorController;
use App\Http\Controllers\api\v2\CtaController;
use App\Http\Controllers\api\v4\Form\ChatbotFormController;
use App\Http\Controllers\api\v4\SocialIntegrationController;
use App\Http\Controllers\api\v5\AdminCopilotController;
use App\Http\Controllers\api\v5\AIEngagementController;
use App\Http\Controllers\api\v5\MCPAgentController;
use App\Http\Controllers\api\v5\MCPCapabilityController;
use App\Http\Controllers\api\v5\MCPConnectorController;
use App\Http\Controllers\api\v5\Microsoft365SyncController;
use App\Http\Controllers\api\v5\MCPPendingActionController;
use App\Http\Controllers\api\v5\MCPPermissionController;
use App\Http\Controllers\api\v5\MCPWorkflowController;
use App\Http\Controllers\api\v5\ModuleCatalogController;
use App\Http\Controllers\api\v5\ModuleSubscriptionController;
use App\Http\Controllers\api\v5\ProactiveEngagementController;
use App\Http\Controllers\api\v5\SalesProspectingController;
use App\Http\Controllers\web\v4\FacebookConnectController;
use App\Http\Controllers\web\v4\InstagramConnectController;
use App\Http\Controllers\web\v4\YouTubeConnectController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::controller(AuthController::class)->group(function () {
        Route::post('/register', 'register')->name('api.register');
        Route::post('/verify-code', 'verify')->name('api.verify');
        Route::post('/resend-code', 'resend')->name('api.resend-code');
        Route::post('/login', 'login')->name('api.login');
        Route::post('/logout', 'logout')->name('api.logout')->middleware('jwt.auth');
        Route::post('/refresh-token', 'refreshToken')->name('api.refresh-token')->middleware('jwt.auth');
        Route::post('/forgot-password', 'sendPasswordResetCode')->name('api.send-password-reset-code');
        Route::post('/reset-password', 'resetPasswordWithCode')->name('api.reset-password-with-code');
        Route::get('/me', 'me')->name('api.me')->middleware('jwt.auth');
    });
    Route::middleware('jwt.auth')->group(function () {
        Route::controller(DashboardController::class)->group(function () {
            Route::get('/dashboard/overview', 'overview');
            Route::get('/dashboard/site/{id}/overview', 'siteOverview');
        });
        Route::apiResource('site', SiteController::class);
        Route::controller(SiteController::class)->group(function () {
            Route::post('site/{id}/crawl', 'crawl');
            // Route::post('site/{site_id}/documents', 'uploadDocument');
            Route::get('site/{siteId}/pages/overview', 'pagesOverview');
            Route::get('site/{site}/widget-test', 'widgetTest');
            Route::get('/site/{site_id}/widget/config', 'widgetConfig');
            Route::post('/site/sitemap', 'generateSitemap');
            Route::post('/knowledge-quality/calculate', 'calculateKnowledgeQuality');
            // Route::post('/api/products/{productIndex}/reindex', 'reindexProducts');
        });
        Route::post('/chat/ask', [ChatController::class, 'ask']);
        Route::apiResource('conversation', ConversationController::class)->except(['store', 'update']);
        Route::controller(ConversationController::class)->group(function () {
            Route::get('/conversation/{conversationId}/{siteId}', 'messages');
            Route::get('/conversation/{conversationId}/{siteId}/admin', 'messagesAdmin');
            Route::get('/conversation/{conversationId}/site/{siteId}/user/{userId}', 'messagesByUser');
            Route::get('/site/{siteId}/users/{userId}/conversations', 'conversationsByUser');

            Route::get('sites/{siteId}/conversations', 'index');
            Route::get('site/{siteId}/conversations/{conversation}', 'show');
            Route::get('site/{siteId}/conversations/{conversation}/messages', 'adminMessages');
            Route::patch('conversations/{conversation}/status', 'updateStatus');
            Route::post('conversations/{conversation}/convert-to-user', 'convertToUser');
        });
        Route::post('/site/{site}/manual-content', [ManualContentController::class, 'store']);
        Route::post('/site/{site}/sitemap', [SitemapController::class, 'store']);
        Route::controller(DocumentController::class)->prefix('/site/{site}/documents')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{document}', 'show');
            Route::post('/{document}', 'update');
            Route::delete('/{document}', 'destroy');
            Route::post('/{document}/reindex', 'reindex');
        });
        Route::apiResource('type_site', TypeSiteController::class)->only(['index']);
        Route::apiResource('widget_setting', WidgetSettingController::class)->except(['index']);
        Route::controller(WidgetSettingController::class)->group(function () {
            Route::get('site/{site}/widget/setting', 'index');
        });
        Route::apiResource('ai_role', AIRoleController::class);
        Route::controller(ChunkController::class)->group(function () {
            Route::get('chunk/{site}/products', 'indexProducts');
            Route::post('chunk/product/{site}/{product_id}/reindex', 'reindexProduct');
            Route::delete('site/{site}/product/{product_id}', 'deleteProduct');
            Route::delete('site/{site}/products', 'deleteProducts');
        });
        Route::controller(PageController::class)->group(function () {
            Route::post('/pages/{page}/recrawl', 'recrawl');
            Route::post('site/{site}/pages/import', 'import');
            Route::delete('/pages', [PageController::class, 'destroyMultiple']);
            Route::delete('/pages/{page}', [PageController::class, 'destroy']);
        });
        Route::controller(UserController::class)->group(function () {
            Route::get('/users/site/{site}', 'index')->whereUuid('site');
            Route::get('users/{userId}/site/{site}', 'show')->whereUuid(['userId', 'site']);
        });

        Route::controller(CtaController::class)->group(function () {
            Route::delete('site/{site}/ctas', 'destroyAll');
            Route::delete('site/{site}/ctas/bulk', 'destroyMultiple');
            Route::post('site/{site}/cta/forms/submit', 'submitForm');
        });
        Route::apiResource('site.ctas', CtaController::class);

        Route::controller(SocialIntegrationController::class)->group(function () {
            Route::get('/site/{site}/integrations', 'integrations');
            Route::post('/site/{site}/integrations/auto-reply', 'setAutoReply');
            Route::post('/site/{siteId}/integrations/{provider}/conversations/{conversationId}/reply', 'reply');
        });

        Route::controller(ChatbotFormController::class)->group(function () {
            Route::get('/sites/{siteId}/forms', 'index');
            Route::get('/sites/{siteId}/forms/active', 'active');
            Route::get('/sites/{siteId}/forms/{formId}', 'show');
            Route::post('/sites/{siteId}/forms', 'store');
            Route::put('/sites/{siteId}/forms/{formId}', 'update');
            Route::delete('/sites/{siteId}/forms/{formId}', 'destroy');
            Route::get('/sites/{siteId}/forms/{formId}/duplicate', 'duplicate');
            Route::get('/sites/{siteId}/forms/{formId}/submissions', 'submissions');
        });

        // Dans le groupe jwt.auth (admin dashboard)
        Route::get('site/{site}/analytics/resource-events', [ResourceEventAnalyticsController::class, 'index']);
        Route::prefix('site/{site}/analytics')->controller(AnalyticsController::class)->group(function () {
            Route::get('/overview', 'overview');
            Route::get('/business-impact', 'businessImpact');
            Route::get('/funnel', 'funnel');
            Route::get('/knowledge', 'knowledge');
            Route::get('/agents', 'agents');
            Route::get('/workflows', 'workflows');
            Route::get('/mcp', 'mcp');
            Route::get('/recommendations', 'recommendations');
            Route::get('/anomalies', 'anomalies');
        });

        Route::prefix('site/{site}/visitor-intelligence')->controller(VisitorIntelligenceController::class)->group(function () {
            Route::get('/overview', 'overview');
            Route::get('/sessions', 'sessions');
            Route::get('/visitors', 'visitors');
            Route::get('/sessions/{session}', 'session');
            Route::get('/sessions/{session}/replay', 'replay');
            Route::get('/sessions/{session}/replay/chunks/{chunk}', 'replayChunk');
            Route::delete('/sessions/{session}', 'deleteSession');
            Route::get('/journey', 'journey');
            Route::get('/opportunities', 'opportunities');
            Route::get('/actions', 'actions');
            Route::get('/rules', 'rules');
            Route::post('/rules', 'storeRule');
            Route::put('/rules/{rule}', 'updateRule');
            Route::delete('/rules/{rule}', 'destroyRule');
            Route::post('/actions/{action}/approve', 'approveAction');
            Route::post('/actions/{action}/execute', 'executeAction');
        });

        Route::prefix('/site/{site}/mcp')->group(function () {

            Route::controller(Microsoft365SyncController::class)->prefix('/microsoft-365')->group(function () {
                Route::get('/sources', 'index');
                Route::post('/sync', 'sync');
            });

            Route::controller(MCPConnectorController::class)->group(function () {
                Route::get('/connectors', 'index');
                Route::post('/connectors/{slug}/activate', 'activateWithApiKey');
                Route::post('/connectors/{slug}/deactivate', 'deactivate');
                Route::get('/connectors/{slug}/oauth/redirect', 'oauthRedirect');
                Route::put('/connectors/{slug}/settings', 'updateSettings'); // 🆕
                Route::get('/connectors/{slug}/settings', 'getSettings'); // 🆕
            });

            Route::controller(MCPPermissionController::class)->group(function () {
                Route::get('/permissions', 'index');
                Route::put('/permissions', 'update');
            });

            Route::controller(MCPPendingActionController::class)->group(function () {
                // 🆕 File d'attente back-office des actions à valider par un admin
                Route::get('/pending-actions', 'index');
                // 🆕 Résolution d'une action en attente — accessible à la fois au widget
                // visiteur (confirm_actor='visitor', pas d'auth requise, vérifié par
                // possession de la conversation) et à l'admin authentifié (confirm_actor='admin').
                // Retirez auth:sanctum ici : l'autorisation fine est faite DANS le contrôleur
                // selon confirm_actor (voir MCPPendingActionController::resolve).
                Route::post('/pending-actions/{pendingAction}/resolve', 'resolve');
            });

            Route::controller(MCPCapabilityController::class)->group(function () {
                Route::get('/capabilities/tools-catalog', 'toolsCatalog'); // 🆕
                Route::get('/capabilities/definitions', 'definitions'); // 🆕
                Route::post('/capabilities/definitions', 'store'); // 🆕
                Route::put('/capabilities/definitions/{capability}', 'update'); // 🆕
                Route::delete('/capabilities/definitions/{capability}', 'destroy'); // 🆕
                Route::post('/capabilities/suggest', 'suggest'); // 🆕
                Route::get('/capabilities/recommended', 'recommended'); // 🆕
                Route::post('/capabilities/recommended/{key}/dismiss', 'dismissRecommendation'); // 🆕
                Route::get('/capabilities', 'index');
                Route::get('/capabilities/catalog', 'catalog');
                Route::put('/capabilities', 'updatePreference'); // 🆕 renommé (était 'update', conflit de nom résolu)
                Route::get('/capabilities/recommended-actions', 'recommendedActions'); // 🆕
                Route::post('/capabilities/recommended-actions/{key}/accept', 'acceptActionRecommendation'); // 🆕
                Route::post('/capabilities/recommended-actions/{key}/dismiss', 'dismissActionRecommendation'); // 🆕
            });

            Route::controller(MCPWorkflowController::class)->group(function () {
                Route::get('/workflows', 'index');
                Route::post('/workflows', 'store');
                Route::put('/workflows/{workflow}', 'update');
                Route::delete('/workflows/{workflow}', 'destroy');
                Route::get('/workflows/{workflow}/dependencies', 'dependencies'); // 🆕
                Route::post('/workflows/{workflow}/install', 'install'); // 🆕
            });

            Route::controller(MCPAgentController::class)->group(function () {
                Route::get('/agents/skills-catalog', 'skillsCatalog');
                Route::get('/agents', 'index');
                Route::post('/agents', 'store');
                Route::put('/agents/{agent}', 'update');
                Route::delete('/agents/{agent}', 'destroy');
                Route::post('/agents/{agent}/publish', 'publish');
                Route::post('/agents/{agent}/unpublish', 'unpublish');
                Route::post('/agents/{agent}/set-fallback', 'setAsFallback'); // 🆕
            });

            Route::controller(SalesProspectingController::class)->group(function () {
                Route::get('/prospecting-sources', 'sourceCatalog');
                Route::get('/agent-templates', 'templates');
                Route::post('/agent-templates/{templateKey}/install', 'installTemplate');
                Route::delete('/agent-templates/{templateKey}', 'uninstallTemplate');

                Route::get('/agents/{agent}/prospecting-config', 'getConfig');
                Route::put('/agents/{agent}/prospecting-config', 'updateConfig');
                Route::post('/agents/{agent}/prospecting-campaigns', 'storeCampaign');

                Route::get('/prospecting-campaigns', 'campaigns');
                Route::get('/prospecting-campaigns/{campaign}', 'showCampaign');
                Route::post('/prospecting-campaigns/{campaign}/run', 'runCampaign');
                Route::post('/prospecting-campaigns/{campaign}/force-run', 'forceRunCampaign');
                Route::post('/prospecting-campaigns/{campaign}/stop', 'stopCampaign');
                Route::delete('/prospecting-campaigns/{campaign}', 'destroyCampaign');
                Route::get('/prospecting-campaigns/{campaign}/prospects/export', 'exportCampaignProspects');
                Route::get('/prospecting-campaigns/{campaign}/prospects', 'campaignProspects');
                Route::post('/prospecting-campaigns/{campaign}/sync-crm', 'syncCampaignProspectsToCrm');

                Route::get('/prospects/{prospect}', 'showProspect');
                Route::post('/prospects/{prospect}/sync-crm', 'syncProspectToCrm');
            });

        });

        Route::prefix('/site/{site}/proactive')->controller(ProactiveEngagementController::class)->group(function () {
            Route::get('/campaigns', 'index');
            Route::post('/campaigns', 'store');
            Route::get('/campaigns/{campaign}', 'show');
            Route::put('/campaigns/{campaign}', 'update');
            Route::delete('/campaigns/{campaign}', 'destroy');
            Route::post('/campaigns/{campaign}/activate', 'activate');
            Route::post('/campaigns/{campaign}/pause', 'pause');
            Route::post('/campaigns/{campaign}/stop', 'stop');
            Route::post('/campaigns/{campaign}/schedule', 'schedule');
            Route::get('/messages', 'messages');
            Route::post('/messages/{message}/cancel', 'cancelMessage');
            Route::get('/messages/{message}/why', 'why');
            Route::get('/history', 'history');
            Route::get('/outcomes', 'outcomes');
            Route::get('/stats', 'stats');
        });

        Route::prefix('/site/{site}/ai-engagement')->controller(AIEngagementController::class)->group(function () {
            Route::get('/', 'show');
            Route::put('/', 'update');
            Route::get('/decisions', 'decisions');
            Route::get('/stats', 'stats');
        });

        Route::controller(ModuleCatalogController::class)->group(function () {
            Route::get('/modules/catalog', 'index')->name('modules.catalog');
            Route::get('/subscription/summary', 'summary')->name('subscription.summary');
        });

        Route::controller(ModuleSubscriptionController::class)->group(function () {
            // Activation / désactivation / upgrade
            Route::post('/modules/{slug}/trial', 'startTrial')->name('modules.trial');
            Route::post('/modules/{slug}/purchase', 'purchase')->name('modules.purchase');
            Route::post('/modules/{slug}/deactivate', 'deactivate')->name('modules.deactivate');
            Route::post('/modules/{slug}/upgrade', 'upgrade')->name('modules.upgrade');

            // Coupons
            Route::post('/subscription/coupon', 'applyCoupon')->name('subscription.coupon');
            // Conversion trial → payant
            Route::post('/subscription/trial/convert', 'convertTrial')->name('subscription.trial.convert');
        });

        Route::prefix('/site/{site}/admin-copilot')->group(function () {

            Route::controller(AdminCopilotController::class)->group(function () {
                Route::get('/conversations', 'conversations');
                Route::get('/conversations/{conversation}', 'show');
                Route::put('/conversations/{conversation}/title', 'rename');
                Route::delete('/conversations/{conversation}', 'destroy');
                Route::post('/ask', 'ask');
            });

        });

    });

    Route::controller(SiteController::class)->group(function () {
        Route::get('/site/{site_id}/widget/config', 'widgetConfig');
    });
    Route::post('/login/token', [AuthController::class, 'loginWithToken']);
    Route::prefix('widget')->group(function () {
        Route::controller(WidgetVisitorController::class)->group(function () {
            Route::post('/visitor/init', 'init');
            Route::post('/chat', 'chat');
            // Récupérer toutes les conversations d’un visitor
            Route::get('conversations/{siteId}', 'visitorConversations');
            // Récupérer les messages d’une conversation d’un visitor
            Route::get('chat/{conversationId}/{siteId}', 'visitorMessages');
            Route::get('/config/{siteId}', 'widgetConfig');
        });
        Route::controller(CtaController::class)->group(function () {
            Route::post('site/{site}/cta/forms/submit', 'submitForm');
        });

        Route::controller(ChatbotFormController::class)->group(function () {
            Route::get('/sites/{siteId}/forms/{form}', 'public_show');
            Route::post('/sites/{siteId}/forms/{form}/submissions', 'submitForm');
        });

        Route::controller(ProactiveWidgetController::class)->middleware('throttle:120,1')->group(function () {
            Route::get('/proactive/pending/{site}', 'pending');
            Route::post('/proactive/{site}/messages/{message}/opened', 'opened');
            Route::post('/proactive/{site}/messages/{message}/opt-out', 'optOut');
        });

        // Dans le groupe widget (public, visiteur)
        Route::post('/site/{site}/resource-events', [ResourceEventController::class, 'store'])
            ->middleware(['widget.origin', 'throttle:120,1']);
        Route::post('/site/{site}/visitor-intelligence/events', [VisitorIntelligenceIngestionController::class, 'store'])
            ->middleware(['widget.origin', 'throttle:300,1']);
        Route::post('/site/{site}/visitor-intelligence/frames', [VisitorIntelligenceIngestionController::class, 'frame'])
            ->middleware(['widget.origin', 'throttle:300,1']);
        Route::post('/site/{site}/visitor-intelligence/replay-chunks', [VisitorIntelligenceIngestionController::class, 'replayChunk'])
            ->middleware(['widget.origin', 'throttle:120,1']);
    });

    Route::prefix('social')->group(function () {

        Route::prefix('/facebook')->controller(FacebookConnectController::class)->group(function () {
            Route::post('/store-page/{siteId}', 'storePage');
        });

        Route::prefix('/youtube')->controller(YouTubeConnectController::class)->group(function () {
            Route::post('/store-channel/{siteId}', 'storeChannel');
        });

        Route::prefix('/instagram')->controller(InstagramConnectController::class)->group(function () {
            Route::post('/store-account/{siteId}', 'storeAccount');
        });

    });

    /*Route::post('/conversations/{conversation}/confirm-mcp-action', [
        ChatController::class, 'confirmMcpAction',
    ]);

    Route::post('/mcp/pending-actions/{pendingAction}/resolve', [
        MCPPendingActionController::class, 'resolve',
    ]);*/

});

<?php

use App\Http\Controllers\api\v5\MCPConnectorController;
use App\Http\Controllers\web\PageController;
#use App\Http\Controllers\web\v1\FacebookWebhookController;
use App\Http\Controllers\web\v1\GoogleController;
use App\Http\Controllers\web\v4\EmailConnectController;
use App\Http\Controllers\web\v4\EmailWebhookController;
use App\Http\Controllers\web\v4\FacebookConnectController;
use App\Http\Controllers\web\v4\FacebookWebhookController;
use App\Http\Controllers\web\v4\InstagramConnectController;
use App\Http\Controllers\web\v4\InstagramWebhookController;
use App\Http\Controllers\web\v4\SlackConnectController;
use App\Http\Controllers\web\v4\SlackWebhookController;
use App\Http\Controllers\web\v4\TelegramConnectController;
use App\Http\Controllers\web\v4\TelegramWebhookController;
use App\Http\Controllers\web\v4\WhatsAppEmbeddedSignupController;
use App\Http\Controllers\web\v4\WhatsAppWebhookController;
use App\Http\Controllers\web\v4\YouTubeConnectController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

Route::get('/', function () {
    /*foreach (\App\Models\TypeSite::all() as $type) {
        $type->update([
            'slug' => Str::slug($type->name),
        ]);
    }*/
    return redirect()->route('home.page');
});


Route::controller(PageController::class)->group(function () {
    Route::get('/accueil', 'home')->name('home.page');
    Route::get('/a-propos', 'about')->name('about.page');
    Route::get('services', 'services')->name('services.page');
    Route::get('service/{slug}', 'service')->name('service.single');
    Route::get('/tarifs', 'abonnements')->name('abonnements.page');
    Route::get('/faqs', 'faqs')->name('faqs.page');
    Route::get('/contact', 'contact')->name('contact.page');
    Route::post('/contact/send', 'sendContact')->name('contact.send');
    Route::get('/politique-de-confidentialite', 'politique_de_confidentialite')->name('politique_de_confidentialite.page');
    Route::get('/conditions-generales-d-utilisation', 'cgu')->name('cgu.page');
    Route::get('/mentions-legales', 'ml')->name('ml.page');
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.login');

Route::get('/auth/google/callback', [GoogleController::class, 'callback']);

Route::prefix('webhooks')->group(function () {

    Route::prefix('/facebook')->controller(FacebookWebhookController::class)->group(function (){
        Route::get('/', 'verify');
        Route::post('/', 'handle');
    });

    Route::prefix('/instagram')->controller(InstagramWebhookController::class)->group(function (){
        Route::get('/', 'verify');
        Route::post('/', 'handle');
    });

    Route::prefix('/slack')->controller(SlackWebhookController::class)->group(function (){
        Route::post('/', 'handle');
    });

    Route::prefix('/telegram')->controller(TelegramWebhookController::class)->group(function (){
        Route::post('/{accountId}', 'handle')->name('webhook.telegram');
    });

    Route::prefix('/email')->controller(EmailWebhookController::class)->group(function (){
        Route::post('/gmail',   'handleGmail')->name('webhook.email.gmail');
        Route::post('/outlook', 'handleOutlook')->name('webhook.email.outlook');
    });

    Route::prefix('/whatsapp')->controller(WhatsAppWebhookController::class)->group(function (){
        Route::get('/', 'verify');
        Route::post('/', 'handle');
    });

});

Route::prefix('social')->group(function (){

    Route::prefix('/facebook')->controller(FacebookConnectController::class)->group(function (){
        Route::get('/connect/{site}', 'redirect');
        Route::get('/callback', 'callback');
        // Déconnecter une page spécifique
        Route::delete('/disconnect/{siteId}/page/{pageId}', 'disconnectPage');
        // Déconnecter tout Facebook (toutes les pages du site)
        Route::delete('/disconnect/{siteId}', 'disconnect');
    });

    Route::prefix('/youtube')->controller(YouTubeConnectController::class)->group(function (){
        Route::get('/connect/{siteId}','redirect');
        Route::get('/callback','callback');
        Route::delete('/disconnect/{siteId}', 'disconnect'); // 👈 à ajouter
    });

    Route::prefix('/instagram')->controller(InstagramConnectController::class)->group(function (){
        Route::get('/connect/{siteId}','redirect');
        Route::get('/callback','callback');
    });

    Route::prefix('/slack')->controller(SlackConnectController::class)->group(function (){
        Route::get('/connect/{siteId}','redirect');
        Route::get('/callback','callback');
        Route::get('/install',function () {
            return Socialite::driver('slack')
                ->scopes([
                    'assistant:write',
                    'chat:write',
                    'channels:read',
                    'channels:history',
                    'groups:read',
                    'groups:history',
                    'channels:manage',
                ])
                ->redirect();
        });
    });

    Route::prefix('/telegram')->controller(TelegramConnectController::class)->group(function (){
        Route::post('/connect/{siteId}','connect');
        Route::delete('/disconnect/{siteId}','disconnect');
    });

    Route::prefix('/email')->controller(EmailConnectController::class)->group(function (){
        Route::get('redirect/{siteId}', 'redirect');
        Route::post('connect/{siteId}', 'connect');   // IMAP
        Route::delete('disconnect/{siteId}', 'disconnect');
        // OAuth callbacks (pas de jwt.auth — appelé par Google/Microsoft)
        Route::get('/callback/gmail',   'callbackGmail');
        Route::get('/callback/outlook', 'callbackOutlook');
    });

    Route::prefix('/whatsapp')->controller(WhatsAppEmbeddedSignupController::class)->group(function (){
        Route::post('/exchange-code/{siteId}','exchangeCode');
        Route::post('select-phone/{siteId}', 'selectPhone');
        Route::delete('/disconnect/{siteId}','disconnect');
    });

});

Route::get('/site/{site}/mcp/connectors/{slug}/oauth/callback', [MCPConnectorController::class, 'oauthCallback']);
Route::get('/mcp/connectors/{slug}/oauth/callback', [MCPConnectorController::class, 'oauthCallback'])->name('mcp.oauth.callback'); // 🆕;


Route::get('/app/{any?}', function () {
    return response()->file(
        public_path('angular/index.html')
    );
})->where('any', '.*');

Route::get('/widget/{any?}', function () {
    return response()->file(
        public_path('widget/index.html')
    );
})->where('any', '.*');

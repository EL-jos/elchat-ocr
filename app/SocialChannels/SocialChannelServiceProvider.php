<?php

namespace App\SocialChannels;

use App\SocialChannels\YouTube\YouTubeChannel;
use App\SocialChannels\ChannelManager;
use Illuminate\Support\ServiceProvider;
class SocialChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelManager::class, function () {

            $manager = new ChannelManager();

            // YouTube (exemple avec tokens dynamiques plus tard)
            $manager->register(
                new YouTubeChannel(
                    accessToken: config('services.youtube.token'),
                    refreshToken: config('services.youtube.refresh_token'),
                    clientId: config('services.youtube.client_id'),
                    clientSecret: config('services.youtube.client_secret')
                )
            );

            return $manager;
        });
    }
}

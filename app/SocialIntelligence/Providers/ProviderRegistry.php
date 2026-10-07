<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use App\SocialIntelligence\Contracts\SocialIntelligenceProviderInterface;
use InvalidArgumentException;

class ProviderRegistry
{
    /** @var array<string, SocialIntelligenceProviderInterface> */
    private array $providers;

    public function __construct(InstagramProvider $instagram, FacebookProvider $facebook, YouTubeProvider $youtube, TelegramProvider $telegram)
    {
        $this->providers = compact('instagram', 'facebook', 'youtube', 'telegram');
    }

    public function for(SocialAccount $account): SocialIntelligenceProviderInterface
    {
        $provider = $account->provider instanceof \BackedEnum ? $account->provider->value : (string) $account->provider;
        if (!isset($this->providers[$provider])) throw new InvalidArgumentException("Social Intelligence provider [{$provider}] unsupported.");
        return $this->providers[$provider];
    }

    public function capabilities(SocialAccount $account): array { return $this->for($account)->capabilities($account); }
}

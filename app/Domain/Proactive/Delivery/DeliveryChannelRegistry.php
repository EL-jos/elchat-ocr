<?php

namespace App\Domain\Proactive\Delivery;

use App\Domain\Proactive\Contracts\DeliveryChannel;
use App\Domain\Proactive\Delivery\Channels\EmailChannel;
use App\Domain\Proactive\Delivery\Channels\FacebookChannel;
use App\Domain\Proactive\Delivery\Channels\InstagramChannel;
use App\Domain\Proactive\Delivery\Channels\TelegramChannel;
use App\Domain\Proactive\Delivery\Channels\WebsiteWidgetChannel;
use App\Domain\Proactive\Delivery\Channels\WhatsAppChannel;
use App\Domain\Proactive\Delivery\Channels\YouTubeChannel;

class DeliveryChannelRegistry
{
    /** @var array<string, DeliveryChannel> */
    private array $channels;

    public function __construct(
        WebsiteWidgetChannel $website,
        FacebookChannel $facebook,
        InstagramChannel $instagram,
        TelegramChannel $telegram,
        YouTubeChannel $youtube,
        EmailChannel $email,
        WhatsAppChannel $whatsapp,
    ) {
        $this->channels = collect([$website, $facebook, $instagram, $telegram, $youtube, $email, $whatsapp])
            ->mapWithKeys(fn (DeliveryChannel $channel) => [$channel->channel() => $channel])
            ->all();
    }

    public function get(string $channel): ?DeliveryChannel
    {
        return $this->channels[$channel] ?? null;
    }

    public function availability(): array
    {
        return collect($this->channels)->map(fn ($adapter, $channel) => [
            'channel' => $channel,
            'enabled' => (bool) config("proactive.channels.{$channel}.enabled", false),
        ])->values()->all();
    }
}

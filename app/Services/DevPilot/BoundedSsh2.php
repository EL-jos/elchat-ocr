<?php

namespace App\Services\DevPilot;

use phpseclib3\Net\SSH2;
use ReflectionProperty;

/**
 * phpseclib 3 keeps stderr in a private string even when exec uses a streaming
 * callback. Bound that buffer at each SSH packet and expose the packet channel
 * so DevPilot can preserve stdout/stderr separately without buffering unbounded
 * remote output in PHP memory.
 */
final class BoundedSsh2 extends SSH2
{
    public bool $lastPacketWasStderr = false;

    private static ?ReflectionProperty $stderrLogProperty = null;

    public function __construct(string $host, int $port, int $timeout, private readonly int $stderrLimit)
    {
        parent::__construct($host, $port, $timeout);
    }

    protected function get_channel_packet($client_channel, $skip_extended = false)
    {
        $property = self::$stderrLogProperty ??= new ReflectionProperty(SSH2::class, 'stdErrorLog');
        $before = (string) $property->getValue($this);
        $this->lastPacketWasStderr = false;
        $packet = parent::get_channel_packet($client_channel, $skip_extended);
        $after = (string) $property->getValue($this);
        $this->lastPacketWasStderr = strlen($after) > strlen($before);
        if (strlen($after) > $this->stderrLimit) $property->setValue($this, substr($after, -$this->stderrLimit));
        return $packet;
    }
}

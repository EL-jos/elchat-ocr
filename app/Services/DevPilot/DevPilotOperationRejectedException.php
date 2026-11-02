<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** A deterministic local rejection raised before a remote mutation is sent. */
final class DevPilotOperationRejectedException extends RuntimeException {}

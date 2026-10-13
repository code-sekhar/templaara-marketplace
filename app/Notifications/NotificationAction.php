<?php

namespace App\Notifications;

class NotificationAction
{
    public static function safe(mixed $path): bool
    {
        if (! is_string($path) || strlen($path) > 2048) {
            return false;
        }

        // Only known application destinations. No arbitrary URLs, query redirects or encoded separators.
        return preg_match('#^/(account/(orders|refunds|reviews|support)/[A-Za-z0-9_-]+|author/(onboarding|(?:products|earnings|payouts|reviews|support)/[A-Za-z0-9_-]+)|workspace/products/[0-9]+/updates/[0-9]+)$#D', $path) === 1;
    }
}

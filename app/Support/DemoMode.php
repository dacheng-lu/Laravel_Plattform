<?php

namespace App\Support;

use LogicException;

/**
 * Single source of truth for the demo mode: the prefilled login form, the "Demo-Zugänge" list on the login page
 * and the demo schools all ask here instead of reading the configuration themselves.
 *
 * Demo mode is on only when the instance runs with APP_ENV local, testing, staging or demo, DEMO_MODE is true
 * and DEMO_PASSWORD is set (see config/lernplattform.php, demo). Anything else means off.
 */
final class DemoMode
{
    /**
     * Whether demo logins are switched on and a password is set. Both are needed: without a password the demo
     * accounts would be unusable, so the login page would offer logins that cannot work.
     */
    public static function isActive(): bool
    {
        return (bool) config('lernplattform.demo.enabled') && filled(config('lernplattform.demo.password'));
    }

    /**
     * The password every demo account has.
     *
     * @throws LogicException when demo mode is off, so no caller can hand out an empty password by accident
     */
    public static function password(): string
    {
        if (! self::isActive()) {
            throw new LogicException('Demo mode is not active.');
        }

        return (string) config('lernplattform.demo.password');
    }
}

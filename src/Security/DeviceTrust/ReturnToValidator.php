<?php

namespace App\Security\DeviceTrust;

/**
 * Guards the "return_to" redirect target on the trust-device/check-device
 * endpoints against open-redirect abuse: only https URLs on yzendra.fr or
 * one of its subdomains are ever followed.
 */
class ReturnToValidator
{
    public static function isAllowed(?string $returnTo): bool
    {
        if (null === $returnTo || '' === $returnTo) {
            return false;
        }

        $parts = parse_url($returnTo);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ('https' !== $parts['scheme']) {
            return false;
        }

        $host = strtolower($parts['host']);

        return 'yzendra.fr' === $host || str_ends_with($host, '.yzendra.fr');
    }
}

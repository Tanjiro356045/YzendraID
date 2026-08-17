<?php

namespace App\Security\DeviceTrust;

/**
 * Signs/verifies short-lived tickets exchanged during the cross-app device
 * trust redirect (see DeviceTrustController). Deliberately NOT a lexik JWT:
 * a real JWT bearer token would also be accepted by the `api` firewall's
 * `jwt: ~` authenticator, letting a 2-minute redirect ticket double as an
 * API credential. This format (base64url(payload).base64url(signature),
 * RSA-signed) is never fed into that firewall, so there's no ambiguity.
 * Reuses the same RSA keypair as lexik/jwt-authentication-bundle - no new
 * secret to provision or keep in sync.
 */
class DeviceTrustTicket
{
    public static function sign(array $payload, string $privateKeyPem, string $passphrase): string
    {
        $body = self::encode($payload);

        $key = openssl_pkey_get_private($privateKeyPem, $passphrase);
        if (false === $key) {
            throw new \RuntimeException('Unable to load the private key to sign a device trust ticket.');
        }

        openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);

        return $body.'.'.self::base64UrlEncode($signature);
    }

    public static function verify(string $ticket, string $publicKeyPem): ?array
    {
        $parts = explode('.', $ticket, 2);
        if (2 !== \count($parts)) {
            return null;
        }
        [$body, $encodedSignature] = $parts;

        $signature = self::base64UrlDecode($encodedSignature);
        if (null === $signature) {
            return null;
        }

        $key = openssl_pkey_get_public($publicKeyPem);
        if (false === $key || 1 !== openssl_verify($body, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if (null === $json) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!\is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function encode(array $payload): string
    {
        return self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}

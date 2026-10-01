<?php

namespace App\Services;

use App\Exceptions\SocialAuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the ID token a customer's phone got from "Continue with Google" /
 * "Sign in with Apple" and returns who it says the person is.
 *
 * The token is a JWT signed by the provider (RS256). We fetch the provider's
 * public keys, verify the signature, and check the issuer, the audience (the
 * token must have been minted for THIS restaurant's app, not some other app),
 * and expiry. Nothing the app sends on its own is trusted.
 */
class SocialIdentityService
{
    private const GOOGLE_KEYS = 'https://www.googleapis.com/oauth2/v3/certs';

    private const APPLE_KEYS = 'https://appleid.apple.com/auth/keys';

    /**
     * @param  string[]  $audiences  the Google client IDs this restaurant's app uses
     * @return array{sub:string,email:?string,email_verified:bool,name:?string}
     */
    public function verifyGoogle(string $idToken, array $audiences): array
    {
        $claims = $this->verify($idToken, self::GOOGLE_KEYS, ['https://accounts.google.com', 'accounts.google.com'], $audiences);

        return $this->identity($claims);
    }

    /**
     * @param  string  $audience  the app's iOS bundle id (what Apple puts in `aud` for native sign-in)
     * @return array{sub:string,email:?string,email_verified:bool,name:?string}
     */
    public function verifyApple(string $identityToken, string $audience): array
    {
        $claims = $this->verify($identityToken, self::APPLE_KEYS, ['https://appleid.apple.com'], [$audience]);

        return $this->identity($claims);
    }

    private function identity(array $claims): array
    {
        $verified = $claims['email_verified'] ?? false;

        return [
            'sub' => (string) $claims['sub'],
            'email' => isset($claims['email']) ? strtolower((string) $claims['email']) : null,
            // Apple sends the boolean as the string "true".
            'email_verified' => $verified === true || $verified === 'true',
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
        ];
    }

    private function verify(string $jwt, string $keysUrl, array $issuers, array $audiences): array
    {
        $audiences = array_values(array_filter($audiences));
        if (! $audiences) {
            throw new SocialAuthException('Sign-in with this provider is not set up for this app.', 422, 'SOCIAL_NOT_CONFIGURED');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SocialAuthException('The sign-in token is not valid.');
        }

        $header = json_decode($this->b64($parts[0]), true);
        $claims = json_decode($this->b64($parts[1]), true);
        $signature = $this->b64($parts[2]);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || empty($header['kid'])) {
            throw new SocialAuthException('The sign-in token is not valid.');
        }

        $jwk = $this->findKey($keysUrl, $header['kid']);
        if (! $jwk) {
            throw new SocialAuthException('The sign-in token is not valid.');
        }

        $ok = openssl_verify($parts[0].'.'.$parts[1], $signature, $this->jwkToPem($jwk), OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new SocialAuthException('The sign-in token is not valid.');
        }

        $aud = $claims['aud'] ?? null;
        $tokenAudiences = is_array($aud) ? $aud : [$aud];

        if (! in_array($claims['iss'] ?? null, $issuers, true)
            || ! array_intersect($tokenAudiences, $audiences)
            || empty($claims['sub'])
            || ! isset($claims['exp']) || (int) $claims['exp'] < time() - 60) {
            throw new SocialAuthException('The sign-in token is not valid for this app, or has expired.');
        }

        return $claims;
    }

    /** The provider's keys are cached for a few hours; an unknown key id forces one refresh (key rotation). */
    private function findKey(string $url, string $kid): ?array
    {
        $cacheKey = 'social_jwks_'.md5($url);

        foreach ([false, true] as $refresh) {
            if ($refresh) {
                Cache::forget($cacheKey);
            }

            $keys = Cache::remember($cacheKey, now()->addHours(6), function () use ($url) {
                $response = Http::timeout(8)->get($url);
                if (! $response->successful()) {
                    throw new SocialAuthException('Could not reach the sign-in provider. Please try again.', 503, 'SOCIAL_PROVIDER_UNAVAILABLE');
                }

                return $response->json('keys') ?? [];
            });

            foreach ($keys as $key) {
                if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                    return $key;
                }
            }
        }

        return null;
    }

    private function b64(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    /** RSA JWK (n, e) -> PEM "PUBLIC KEY" (SubjectPublicKeyInfo) that openssl_verify accepts. */
    private function jwkToPem(array $jwk): string
    {
        $rsaPublicKey = $this->seq($this->int($this->b64($jwk['n'])).$this->int($this->b64($jwk['e'])));
        $bitString = "\x03".$this->len(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $rsaEncryptionOid = hex2bin('300d06092a864886f70d0101010500');
        $spki = $this->seq($rsaEncryptionOid.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function seq(string $content): string
    {
        return "\x30".$this->len(strlen($content)).$content;
    }

    private function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->len(strlen($bytes)).$bytes;
    }

    private function len(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}

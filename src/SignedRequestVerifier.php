<?php

declare(strict_types=1);

namespace Elonn\Time;

/**
 * Verifies Conductor signed Service requests.
 */
final class SignedRequestVerifier
{
    /**
     * @param array<string, string> $staticPublicKeys
     */
    public function __construct(
        private string $keysUrl,
        private array $staticPublicKeys = [],
        private int $timestampToleranceSeconds = 300
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function verify(array $headers, string $method, string $path, string $body): bool
    {
        $keyId = trim((string) ($headers['x-elonn-key-id'] ?? ''));
        $timestamp = trim((string) ($headers['x-elonn-timestamp'] ?? ''));
        $bodyDigest = trim((string) ($headers['x-elonn-body-sha256'] ?? ''));
        $signature = trim((string) ($headers['x-elonn-signature'] ?? ''));
        if ($keyId === '' || $timestamp === '' || $bodyDigest === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > $this->timestampToleranceSeconds) {
            return false;
        }
        if (!hash_equals(base64_encode(hash('sha256', $body, true)), $bodyDigest)) {
            return false;
        }

        $publicKey = $this->publicKey($keyId);
        if ($publicKey === '') {
            return false;
        }

        $canonical = implode("\n", [
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $timestamp,
            $bodyDigest,
        ]);
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        return openssl_verify($canonical, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function publicKey(string $keyId): string
    {
        if (($this->staticPublicKeys[$keyId] ?? '') !== '') {
            return $this->staticPublicKeys[$keyId];
        }
        if ($this->keysUrl === '') {
            return '';
        }

        $body = @file_get_contents($this->keysUrl, false, stream_context_create(['http' => ['timeout' => 5]]));
        $decoded = $body === false ? null : json_decode($body, true);
        foreach ((array) ($decoded['keys'] ?? []) as $key) {
            if (is_array($key) && ($key['id'] ?? '') === $keyId) {
                return (string) ($key['public_key_pem'] ?? '');
            }
        }

        return '';
    }
}

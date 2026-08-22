<?php

declare(strict_types=1);

namespace Elonn\Time;

/**
 * Verifies first-party service callers at Time's internal boundary. conductor.elonn plus a
 * valid signature authenticates immediately; every other caller falls through to the existing
 * static service-token check.
 */
final class ServiceAuthenticator
{
    /** @param array<string, string> $tokensByService */
    public function __construct(
        private readonly array $tokensByService,
        private readonly ?SignedRequestVerifier $signedRequestVerifier = null
    ) {
    }

    /** @return array{service:string, member_id:string}|null */
    public function authenticate(string $method, string $path, string $body): ?array
    {
        $service = trim((string) ($_SERVER['HTTP_X_ELONN_SERVICE'] ?? ''));
        if ($service === '') {
            return null;
        }
        $memberId = trim((string) ($_SERVER['HTTP_X_ELONN_MEMBER_ID'] ?? ''));

        if ($service === 'conductor.elonn' && $this->signedRequestVerifier instanceof SignedRequestVerifier) {
            $signatureHeaders = [
                'x-elonn-key-id' => (string) ($_SERVER['HTTP_X_ELONN_KEY_ID'] ?? ''),
                'x-elonn-timestamp' => (string) ($_SERVER['HTTP_X_ELONN_TIMESTAMP'] ?? ''),
                'x-elonn-body-sha256' => (string) ($_SERVER['HTTP_X_ELONN_BODY_SHA256'] ?? ''),
                'x-elonn-signature' => (string) ($_SERVER['HTTP_X_ELONN_SIGNATURE'] ?? ''),
            ];
            if ($this->signedRequestVerifier->verify($signatureHeaders, $method, $path, $body)) {
                return ['service' => $service, 'member_id' => $memberId];
            }
        }

        $token = trim((string) ($_SERVER['HTTP_X_ELONN_SERVICE_TOKEN'] ?? ''));
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($token === '' && str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));
        }

        $expected = (string) ($this->tokensByService[$service] ?? '');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            return null;
        }

        return ['service' => $service, 'member_id' => $memberId];
    }
}

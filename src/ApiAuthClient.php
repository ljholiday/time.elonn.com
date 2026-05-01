<?php

declare(strict_types=1);

namespace Elonn\Time;

final class ApiAuthClient
{
    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function ready(): bool
    {
        $response = $this->request('GET', '/health', null);
        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * @return array{id: string, email: string}|null
     */
    public function identityForToken(string $token): ?array
    {
        $response = $this->request('GET', '/identity/me', $token);
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            return null;
        }

        $id = $response['json']['id'] ?? null;
        $email = $response['json']['email'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        if (!is_string($email)) {
            return null;
        }

        return [
            'id' => (string) $id,
            'email' => $email,
        ];
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function request(string $method, string $path, ?string $token): array
    {
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        $raw = @file_get_contents($this->baseUrl . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }

        $json = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $json = $decoded;
        }

        return [
            'status' => $status,
            'json' => $json,
        ];
    }
}

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
     * @return array{id: string, email: string, username: string|null, display_name: string|null}|null
     */
    public function identityForToken(string $token): ?array
    {
        $response = $this->request('GET', '/identity/me', $token);
        return $response['status'] === 200 && is_array($response['json'])
            ? $this->identityFromJson($response['json'])
            : null;
    }

    /**
     * @return array{id: string, email: string, username: string|null, display_name: string|null}|null
     */
    public function identityForDavCredentials(string $username, string $password): ?array
    {
        $response = $this->request('POST', '/identity/dav/validate', null, [
            'username' => $username,
            'password' => $password,
        ]);
        return $response['status'] === 200 && is_array($response['json'])
            ? $this->identityFromJson($response['json'])
            : null;
    }

    /**
     * @return array{status: int, json: mixed}
     */
    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, json: mixed}
     */
    private function request(string $method, string $path, ?string $token, ?array $body = null): array
    {
        $headers = ['Accept: application/json'];
        $content = '';
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $content,
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

    /**
     * @param array<string, mixed> $json
     * @return array{id: string, email: string, username: string|null, display_name: string|null}|null
     */
    private function identityFromJson(array $json): ?array
    {
        $id = $json['id'] ?? null;
        $email = $json['email'] ?? null;
        $displayName = $json['display_name'] ?? null;
        $username = $json['username'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        if (!is_string($email)) {
            return null;
        }

        return [
            'id' => (string) $id,
            'email' => $email,
            'username' => is_string($username) ? $username : null,
            'display_name' => is_string($displayName) ? $displayName : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Elonn\Time\Dav;

use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

/**
 * Exposes the authenticated Time member as a single DAV principal.
 */
final class PrincipalBackend extends AbstractBackend
{
    /**
     * @param array{id:string,email:string,display_name:string|null} $identity
     */
    public function __construct(private readonly array $identity)
    {
    }

    public function getPrincipalsByPrefix($prefixPath): array
    {
        return $prefixPath === 'principals' ? [$this->principal()] : [];
    }

    public function getPrincipalByPath($path): ?array
    {
        return $path === $this->principal()['uri'] ? $this->principal() : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch): void
    {
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array
    {
        if ($prefixPath !== 'principals') {
            return [];
        }

        $principal = $this->principal();
        foreach ($searchProperties as $property => $value) {
            if (!isset($principal[$property]) || stripos((string) $principal[$property], (string) $value) === false) {
                return [];
            }
        }
        return [$principal['uri']];
    }

    public function getGroupMemberSet($principal): array
    {
        return [];
    }

    public function getGroupMembership($principal): array
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members): void
    {
    }

    /**
     * @return array<string, string>
     */
    private function principal(): array
    {
        return [
            'uri' => 'principals/' . rawurlencode($this->identity['id']),
            '{DAV:}displayname' => (string) ($this->identity['display_name'] ?: $this->identity['email']),
            '{http://sabredav.org/ns}email-address' => $this->identity['email'],
        ];
    }
}

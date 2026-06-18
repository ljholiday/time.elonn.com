<?php

declare(strict_types=1);

namespace Elonn\Time\Dav;

use Sabre\DAV\Auth\Backend\AbstractBasic;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Reuses credentials already validated by API before SabreDAV dispatch.
 */
final class AuthBackend extends AbstractBasic
{
    public function __construct(
        private readonly string $username,
        private readonly string $principal
    ) {
    }

    protected function validateUserPass($username, $password): bool
    {
        return hash_equals($this->username, (string) $username);
    }

    public function check(RequestInterface $request, ResponseInterface $response): array
    {
        $result = parent::check($request, $response);
        return $result[0] === true ? [true, $this->principal] : $result;
    }
}

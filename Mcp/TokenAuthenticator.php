<?php

namespace Modules\McpForFreescout\Mcp;

use App\User;
use Modules\McpForFreescout\Entities\McpToken;

/**
 * Resolves the FreeScout user behind an `Authorization: Bearer <token>` header.
 *
 * The authenticator has no side effects on the session or the global auth state;
 * the caller decides what to do with the returned user.
 */
class TokenAuthenticator
{
    /**
     * @var McpToken|null
     */
    private $lastToken;

    /**
     * Authenticate a request by its Authorization header.
     *
     * @param string|null $authorizationHeader Raw header value, e.g. "Bearer fsmcp_...".
     * @param string|null $ip                  Client IP, recorded as the token's last used address.
     *
     * @return User|null The token's user, or null if the header is missing or
     *                   malformed, the token is unknown, or the user is not active.
     */
    public function authenticate(?string $authorizationHeader = null, ?string $ip = null): ?User
    {
        $this->lastToken = null;

        $plaintext = $this->extractToken($authorizationHeader);
        if ($plaintext === null) {
            return null;
        }

        $token = McpToken::findByPlaintext($plaintext);
        if ($token === null) {
            return null;
        }

        $user = $token->user;
        if ($user === null || !$user->isActive()) {
            return null;
        }

        $token->markUsed($ip);
        $this->lastToken = $token;

        return $user;
    }

    /**
     * The token matched by the last successful authenticate() call, or null if
     * the last call failed.
     *
     * @return McpToken|null
     */
    public function lastToken(): ?McpToken
    {
        return $this->lastToken;
    }

    private function extractToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null
            || !preg_match('/^Bearer (\S+)$/i', $authorizationHeader, $matches)
        ) {
            return null;
        }

        return $matches[1];
    }
}

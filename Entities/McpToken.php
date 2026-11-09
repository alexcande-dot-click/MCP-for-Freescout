<?php

namespace Modules\McpForFreescout\Entities;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A personal access token that lets an MCP client act as a FreeScout user.
 *
 * Only the SHA-256 hash of a token is stored. The plaintext exists exactly once,
 * in the return value of {@see McpToken::issue()}.
 */
class McpToken extends Model
{
    /**
     * Prefix that makes issued tokens recognisable in logs and secret scanners.
     */
    const PREFIX = 'fsmcp_';

    /**
     * Minimum number of seconds between two "last used" updates of one token.
     */
    const USAGE_UPDATE_INTERVAL = 60;

    protected $table = 'mcpforfreescout_tokens';

    protected $dates = ['last_used_at'];

    protected $hidden = ['token_hash'];

    /**
     * Create a token for a user.
     *
     * @return array [McpToken $token, string $plaintext]
     */
    public static function issue(User $user, string $label): array
    {
        $plaintext = self::PREFIX.bin2hex(random_bytes(24));

        $token = new self();
        $token->forceFill([
            'user_id'      => $user->id,
            'label'        => $label,
            'token_hash'   => self::hash($plaintext),
            'token_prefix' => substr($plaintext, 0, 12),
        ]);
        $token->save();

        return [$token, $plaintext];
    }

    /**
     * Find the token matching a plaintext value.
     */
    public static function findByPlaintext(string $plaintext): ?self
    {
        $hash = self::hash($plaintext);
        $token = self::where('token_hash', $hash)->first();

        if ($token === null || !\Helper::hashEquals($token->token_hash, $hash)) {
            return null;
        }

        return $token;
    }

    /**
     * The user the token acts as.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a successful use of the token.
     *
     * Writes at most once per minute so that busy clients do not cause a
     * database write on every request.
     */
    public function markUsed(?string $ip = null): void
    {
        if ($this->last_used_at !== null
            && $this->last_used_at->diffInSeconds(now()) < self::USAGE_UPDATE_INTERVAL
        ) {
            return;
        }

        $this->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
        ])->save();
    }

    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}

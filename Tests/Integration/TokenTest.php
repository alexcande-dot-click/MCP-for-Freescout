<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\User;
use Modules\McpForFreescout\Entities\McpToken;
use Modules\McpForFreescout\Mcp\TokenAuthenticator;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class TokenTest extends TestCase
{
    public function test_issue_returns_plaintext_once_and_stores_hash_only()
    {
        $user = Fixtures::user();

        list($token, $plaintext) = McpToken::issue($user, 'Claude Desktop');

        $this->assertStringStartsWith('fsmcp_', $plaintext);
        $this->assertSame(54, strlen($plaintext));

        $row = (array) \DB::table('mcpforfreescout_tokens')->where('id', $token->id)->first();
        $this->assertSame(hash('sha256', $plaintext), $row['token_hash']);
        $this->assertSame('Claude Desktop', $row['label']);
        $this->assertSame((int) $user->id, (int) $row['user_id']);
        $this->assertSame(substr($plaintext, 0, 12), $row['token_prefix']);
        foreach ($row as $value) {
            $this->assertNotSame($plaintext, $value);
        }
    }

    public function test_authenticates_valid_bearer_token()
    {
        $user = Fixtures::user();
        list($token, $plaintext) = McpToken::issue($user, 'Agent');
        $authenticator = new TokenAuthenticator();

        $authenticated = $authenticator->authenticate('Bearer '.$plaintext, '203.0.113.7');

        $this->assertNotNull($authenticated);
        $this->assertSame((int) $user->id, (int) $authenticated->id);
        $this->assertSame((int) $token->id, (int) $authenticator->lastToken()->id);

        $fresh = McpToken::find($token->id);
        $this->assertNotNull($fresh->last_used_at);
        $this->assertSame('203.0.113.7', $fresh->last_used_ip);
    }

    public function test_scheme_is_case_insensitive()
    {
        $user = Fixtures::user();
        list(, $plaintext) = McpToken::issue($user, 'Agent');

        $authenticated = (new TokenAuthenticator())->authenticate('bEaReR '.$plaintext);

        $this->assertSame((int) $user->id, (int) $authenticated->id);
    }

    public function test_last_used_is_updated_at_most_once_per_minute()
    {
        $user = Fixtures::user();
        list($token, $plaintext) = McpToken::issue($user, 'Agent');
        $authenticator = new TokenAuthenticator();

        $authenticator->authenticate('Bearer '.$plaintext, '203.0.113.7');
        $authenticator->authenticate('Bearer '.$plaintext, '203.0.113.8');
        $this->assertSame('203.0.113.7', McpToken::find($token->id)->last_used_ip);

        \DB::table('mcpforfreescout_tokens')
            ->where('id', $token->id)
            ->update(['last_used_at' => date('Y-m-d H:i:s', time() - 120)]);
        $authenticator->authenticate('Bearer '.$plaintext, '203.0.113.9');
        $this->assertSame('203.0.113.9', McpToken::find($token->id)->last_used_ip);
    }

    public function test_rejects_missing_malformed_or_unknown_token()
    {
        $user = Fixtures::user();
        list(, $plaintext) = McpToken::issue($user, 'Agent');
        $authenticator = new TokenAuthenticator();

        $this->assertNull($authenticator->authenticate(null));
        $this->assertNull($authenticator->authenticate(''));
        $this->assertNull($authenticator->authenticate('Basic x'));
        $this->assertNull($authenticator->authenticate('Bearer '));
        $this->assertNull($authenticator->authenticate('Bearer  '.$plaintext));
        $this->assertNull($authenticator->authenticate('Bearer '.$plaintext.' extra'));
        $this->assertNull($authenticator->authenticate('Bearer fsmcp_nope'));
        $this->assertNull($authenticator->lastToken());
    }

    public function test_last_token_is_cleared_after_a_failed_attempt()
    {
        $user = Fixtures::user();
        list(, $plaintext) = McpToken::issue($user, 'Agent');
        $authenticator = new TokenAuthenticator();

        $authenticator->authenticate('Bearer '.$plaintext);
        $this->assertNotNull($authenticator->lastToken());

        $authenticator->authenticate('Bearer fsmcp_nope');
        $this->assertNull($authenticator->lastToken());
    }

    public function test_rejects_token_of_disabled_user()
    {
        $user = Fixtures::user();
        list(, $plaintext) = McpToken::issue($user, 'Agent');
        $authenticator = new TokenAuthenticator();

        foreach ([User::STATUS_DISABLED, User::STATUS_DELETED] as $status) {
            $user->status = $status;
            $user->save();

            $this->assertNull($authenticator->authenticate('Bearer '.$plaintext));
            $this->assertNull($authenticator->lastToken());
        }
    }

    public function test_deleting_user_removes_tokens()
    {
        $user = Fixtures::user();
        $other = Fixtures::user();
        McpToken::issue($user, 'First');
        McpToken::issue($user, 'Second');
        McpToken::issue($other, 'Other');

        \Eventy::action('user.deleted', $user, Fixtures::user([], User::ROLE_ADMIN));

        $this->assertSame(0, McpToken::where('user_id', $user->id)->count());
        $this->assertSame(1, McpToken::where('user_id', $other->id)->count());
    }
}

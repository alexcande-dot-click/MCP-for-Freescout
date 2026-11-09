<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\User;
use Illuminate\Http\Request;
use Modules\McpForFreescout\Entities\McpToken;
use Modules\McpForFreescout\Http\Controllers\TokensController;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TokensControllerTest extends TestCase
{
    public function test_user_creates_own_token_and_sees_plaintext_once()
    {
        $user = Fixtures::user();

        $json = $this->ajax($user, [
            'action'  => 'create',
            'user_id' => $user->id,
            'label'   => '  OpenClaw  ',
        ]);

        $this->assertSame('success', $json['status']);
        $this->assertStringStartsWith('fsmcp_', $json['token']);

        $stored = McpToken::where('user_id', $user->id)->get();
        $this->assertCount(1, $stored);
        $this->assertSame('OpenClaw', $stored[0]->label);
        $this->assertSame(hash('sha256', $json['token']), $stored[0]->getAttributes()['token_hash']);

        // The refreshed list shows the prefix only, never the plaintext.
        $this->assertStringContainsString($stored[0]->token_prefix, $json['html']);
        $this->assertStringContainsString('OpenClaw', $json['html']);
        $this->assertStringNotContainsString($json['token'], $json['html']);
    }

    public function test_user_cannot_revoke_someone_elses_token()
    {
        $owner = Fixtures::user();
        $intruder = Fixtures::user();
        list($token) = McpToken::issue($owner, 'Agent');

        $json = $this->ajax($intruder, ['action' => 'revoke', 'token_id' => $token->id]);

        $this->assertSame('error', $json['status']);
        $this->assertNotSame('', $json['msg']);
        $this->assertNotNull(McpToken::find($token->id));
    }

    public function test_user_can_revoke_own_token()
    {
        $user = Fixtures::user();
        list($token) = McpToken::issue($user, 'Agent');

        $json = $this->ajax($user, ['action' => 'revoke', 'token_id' => $token->id]);

        $this->assertSame('success', $json['status']);
        $this->assertNull(McpToken::find($token->id));
    }

    public function test_admin_can_revoke_any_token()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $owner = Fixtures::user();
        list($token) = McpToken::issue($owner, 'Agent');

        $json = $this->ajax($admin, ['action' => 'revoke', 'token_id' => $token->id]);

        $this->assertSame('success', $json['status']);
        $this->assertNull(McpToken::find($token->id));
    }

    public function test_revoking_unknown_token_is_an_error()
    {
        $json = $this->ajax(Fixtures::user(), ['action' => 'revoke', 'token_id' => 999999999]);

        $this->assertSame('error', $json['status']);
    }

    public function test_admin_cannot_create_token_for_another_user()
    {
        $admin = Fixtures::user([], User::ROLE_ADMIN);
        $other = Fixtures::user();

        $json = $this->ajax($admin, ['action' => 'create', 'user_id' => $other->id, 'label' => 'Sneaky']);

        $this->assertSame('error', $json['status']);
        $this->assertArrayNotHasKey('token', $json);
        $this->assertSame(0, McpToken::where('user_id', $other->id)->count());
        $this->assertSame(0, McpToken::where('user_id', $admin->id)->count());
    }

    public function test_user_cannot_create_token_for_another_user()
    {
        $user = Fixtures::user();
        $other = Fixtures::user();

        $json = $this->ajax($user, ['action' => 'create', 'user_id' => $other->id, 'label' => 'Sneaky']);

        $this->assertSame('error', $json['status']);
        $this->assertSame(0, McpToken::where('user_id', $other->id)->count());
    }

    public function test_label_required_and_max_100()
    {
        $user = Fixtures::user();

        foreach (['', '   '] as $label) {
            $json = $this->ajax($user, ['action' => 'create', 'user_id' => $user->id, 'label' => $label]);
            $this->assertSame('error', $json['status'], 'label: "'.$label.'"');
        }

        $json = $this->ajax($user, ['action' => 'create', 'user_id' => $user->id, 'label' => str_repeat('a', 101)]);
        $this->assertSame('error', $json['status']);
        $this->assertSame(0, McpToken::where('user_id', $user->id)->count());

        $json = $this->ajax($user, ['action' => 'create', 'user_id' => $user->id, 'label' => str_repeat('é', 100)]);
        $this->assertSame('success', $json['status']);
        $this->assertSame(1, McpToken::where('user_id', $user->id)->count());
    }

    public function test_non_scalar_parameters_are_an_error_not_a_crash()
    {
        $user = Fixtures::user();
        list($token) = McpToken::issue($user, 'Mine');
        $count = McpToken::where('user_id', $user->id)->count();

        foreach ([
            ['action' => 'create', 'user_id' => $user->id, 'label' => ['x']],
            ['action' => 'create', 'user_id' => [$user->id], 'label' => 'Agent'],
            ['action' => 'revoke', 'token_id' => [$token->id]],
            ['action' => 'revoke', 'token_id' => ['a' => 1]],
        ] as $params) {
            $json = $this->ajax($user, $params);

            $this->assertSame('error', $json['status'], json_encode($params));
            $this->assertNotSame('', $json['msg'], json_encode($params));
        }

        $this->assertSame($count, McpToken::where('user_id', $user->id)->count());
    }

    public function test_unknown_action_is_an_error()
    {
        $json = $this->ajax(Fixtures::user(), ['action' => 'nope']);

        $this->assertSame('error', $json['status']);
    }

    public function test_profile_page_is_forbidden_for_other_regular_users()
    {
        $owner = Fixtures::user();
        $intruder = Fixtures::user();
        \Auth::setUser($intruder);

        try {
            (new TokensController())->profile($owner->id);
            $this->fail('Expected a 403 response');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * Call TokensController@ajax as the given user and decode the JSON answer.
     */
    private function ajax(User $user, array $params): array
    {
        \Auth::setUser($user);
        $request = Request::create('/mcp-tokens/ajax', 'POST', $params);
        app()->instance('request', $request);

        return json_decode((new TokensController())->ajax($request)->getContent(), true);
    }
}

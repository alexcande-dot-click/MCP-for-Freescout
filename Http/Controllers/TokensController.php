<?php

namespace Modules\McpForFreescout\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\McpForFreescout\Entities\McpToken;

/**
 * Token management pages: a user's own tokens on their profile and the
 * administrator overview of all tokens.
 *
 * A token acts as its owner, so only the owner may create one. Administrators
 * may list and revoke any token but never issue one for somebody else.
 */
class TokensController extends Controller
{
    /**
     * Maximum length of a token label.
     */
    const LABEL_MAX_LENGTH = 100;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * The tokens of one user, inside the user's profile menu.
     *
     * @param int $id
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function profile($id)
    {
        $user = User::findOrFail($id);
        if ($user->isDeleted()) {
            abort(404);
        }

        $authUser = auth()->user();
        if ((int) $authUser->id !== (int) $user->id && !$authUser->isAdmin()) {
            abort(403);
        }

        return view('mcpforfreescout::tokens/profile', [
            'user'       => $user,
            'users'      => $authUser->isAdmin() ? User::sortUsers(User::nonDeleted()->get()) : [],
            'tokens'     => $this->tokensOf($user),
            'can_create' => (int) $authUser->id === (int) $user->id,
            'endpoint'   => route('mcpforfreescout.endpoint'),
            'show_owner' => false,
        ]);
    }

    /**
     * Overview of every token, for administrators.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function admin()
    {
        return view('mcpforfreescout::tokens/admin', [
            'tokens'     => McpToken::with('user')->orderBy('created_at', 'desc')->get(),
            'show_owner' => true,
        ]);
    }

    /**
     * Ajax entry point: `create` and `revoke`.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ajax(Request $request)
    {
        $response = [
            'status' => 'error',
            'msg'    => '', // this is error message
        ];

        $authUser = auth()->user();

        switch ($request->action) {
            case 'create':
                $label = $this->isPlainValue($request->label) ? trim((string) $request->label) : null;

                if ($label === null || !$this->isPlainValue($request->user_id)) {
                    $response['msg'] = __('Invalid request.');
                } elseif ((int) $request->user_id !== (int) $authUser->id) {
                    $response['msg'] = __('Tokens can only be created for your own account.');
                } elseif ($label === '') {
                    $response['msg'] = __('Please enter a label for the token.');
                } elseif (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
                    $response['msg'] = __('The label may not be longer than :max characters.', ['max' => self::LABEL_MAX_LENGTH]);
                }

                if (!$response['msg']) {
                    list(, $plaintext) = McpToken::issue($authUser, $label);

                    $response['token'] = $plaintext;
                    $response['html'] = $this->renderTable($this->tokensOf($authUser), false);
                    $response['msg_success'] = __('Token created');
                    $response['status'] = 'success';
                }
                break;

            case 'revoke':
                $token = $this->isPlainValue($request->token_id) ? McpToken::find($request->token_id) : null;

                if (!$this->isPlainValue($request->token_id)) {
                    $response['msg'] = __('Invalid request.');
                } elseif (!$token) {
                    $response['msg'] = __('Token not found.');
                } elseif ((int) $token->user_id !== (int) $authUser->id && !$authUser->isAdmin()) {
                    $response['msg'] = __('Not enough permissions');
                }

                if (!$response['msg']) {
                    $token->delete();

                    $response['msg_success'] = __('Token revoked');
                    $response['status'] = 'success';
                }
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        return \Response::json($response);
    }

    /**
     * Whether a request value is a single value (or absent), not a list such as `label[]=x`.
     *
     * @param mixed $value
     */
    private function isPlainValue($value): bool
    {
        return $value === null || is_scalar($value);
    }

    /**
     * Tokens of a user, newest first.
     */
    private function tokensOf(User $user)
    {
        return McpToken::where('user_id', $user->id)->orderBy('created_at', 'desc')->get();
    }

    /**
     * Render the token table partial.
     */
    private function renderTable($tokens, bool $showOwner): string
    {
        return view('mcpforfreescout::tokens/partials/table', [
            'tokens'     => $tokens,
            'show_owner' => $showOwner,
        ])->render();
    }
}

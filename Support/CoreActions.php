<?php

namespace Modules\McpForFreescout\Support;

use App\Http\Controllers\ConversationsController;
use App\User;
use Illuminate\Http\Request;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Runs FreeScout's own actions in-process, so every change goes through the
 * same permission checks, history entries, events and queued jobs as in the web UI.
 */
final class CoreActions
{
    /**
     * Call the conversation AJAX endpoint (`/conversation/ajax`) as the given user.
     *
     * The request bound in the container and the authenticated user are put back
     * afterwards, even when the call fails.
     *
     * @param User  $user   The user the action is performed as.
     * @param array $params Request parameters, including `action`.
     *
     * @return array The decoded JSON response of the endpoint.
     *
     * @throws ToolError When the endpoint reports an error.
     */
    public static function conversationAjax(User $user, array $params): array
    {
        return self::callAjax($user, ConversationsController::class, '/conversation/ajax', $params);
    }

    /**
     * Call the `ajax` action of a companion module's controller as the given user.
     *
     * The controller must answer like core's AJAX endpoints, with a JSON body whose
     * `status` is `success` or `error` (and `msg`). Request and user are handled as in
     * conversationAjax().
     *
     * @param User   $user            The user the action is performed as.
     * @param string $controllerClass Fully qualified class name of the module's controller.
     * @param array  $params          Request parameters, including `action`.
     *
     * @return array The decoded JSON response of the endpoint.
     *
     * @throws ToolError When the endpoint reports an error.
     */
    public static function moduleAjax(User $user, string $controllerClass, array $params): array
    {
        return self::callAjax($user, $controllerClass, '/', $params);
    }

    private static function callAjax(User $user, string $controllerClass, string $uri, array $params): array
    {
        $previousRequest = app()->bound('request') ? app('request') : null;
        $previousUser = \Auth::user();

        $request = Request::create($uri, 'POST', $params);
        app()->instance('request', $request);
        \Auth::setUser($user);

        try {
            $response = app()->make($controllerClass)->ajax($request);
            $result = json_decode($response->getContent(), true);
        } finally {
            if ($previousRequest !== null) {
                app()->instance('request', $previousRequest);
            }
            if ($previousUser !== null) {
                \Auth::setUser($previousUser);
            }
        }

        if (!is_array($result)) {
            throw new ToolError('FreeScout returned an unreadable response.');
        }
        if (($result['status'] ?? '') !== 'success') {
            throw new ToolError(($result['msg'] ?? '') !== '' ? (string) $result['msg'] : 'FreeScout rejected the action.');
        }

        return $result;
    }
}

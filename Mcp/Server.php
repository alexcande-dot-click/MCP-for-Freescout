<?php

namespace Modules\McpForFreescout\Mcp;

use App\User;

/**
 * Transport-independent MCP server: turns one JSON-RPC request into one response.
 *
 * Two protocol eras are served side by side. The legacy revisions open with an
 * `initialize` handshake (answered statelessly) and are recognised by the
 * MCP-Protocol-Version header; the modern revision is stateless, carries its
 * version in `params._meta` and mirrors method and tool name in headers.
 */
class Server
{
    const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    /**
     * Name of the activity log that records tool calls which change data.
     */
    const AUDIT_LOG = 'mcpforfreescout';

    /**
     * @var ToolRegistry
     */
    private $registry;

    public function __construct(ToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Handle one HTTP request body.
     *
     * @param array  $headers     Request headers, names lower-cased.
     * @param string $rawBody     Raw request body.
     * @param User   $user        The authenticated user the tools act as.
     * @param string $tokenPrefix Display prefix of the token used, for auditing.
     *
     * @return McpResponse
     */
    public function handle(array $headers, string $rawBody, User $user, string $tokenPrefix = ''): McpResponse
    {
        $id = null;

        try {
            $message = $this->decode($rawBody);
            $id = $this->requestId($message);
            $this->assertRequest($message);

            if (!array_key_exists('id', $message)) {
                return new McpResponse(202);
            }

            return $this->respond($id, $this->dispatch($message, $headers, $user, $tokenPrefix));
        } catch (JsonRpcError $e) {
            return $this->respondJson($e->httpStatus(), $e->toArray($id));
        } catch (\Throwable $e) {
            \Helper::logException($e, '[MCP]');

            $error = new JsonRpcError(Protocol::INTERNAL_ERROR, 'Internal server error.', null, 500);

            return $this->respondJson(500, $error->toArray($id));
        }
    }

    /**
     * @throws JsonRpcError
     */
    private function decode(string $rawBody): array
    {
        $message = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonRpcError(Protocol::PARSE_ERROR, 'Parse error: the body is not valid JSON.', null, 400);
        }
        if (!is_array($message)) {
            throw $this->invalidRequest();
        }

        return $message;
    }

    /**
     * The request id when it is a valid one (string or number), otherwise null.
     *
     * @return string|int|float|null
     */
    private function requestId(array $message)
    {
        $id = isset($message['id']) ? $message['id'] : null;

        return is_string($id) || is_int($id) || is_float($id) ? $id : null;
    }

    /**
     * @throws JsonRpcError
     */
    private function assertRequest(array $message)
    {
        $idIsValid = !array_key_exists('id', $message) || $this->requestId($message) !== null;

        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0'
            || !isset($message['method']) || !is_string($message['method']) || $message['method'] === ''
            || !$idIsValid
        ) {
            throw $this->invalidRequest();
        }
    }

    private function invalidRequest(): JsonRpcError
    {
        return new JsonRpcError(Protocol::INVALID_REQUEST, 'Invalid request: expected a single JSON-RPC 2.0 request object.', null, 400);
    }

    /**
     * Produce the result object for a request.
     *
     * @return array|\stdClass
     *
     * @throws JsonRpcError
     */
    private function dispatch(array $message, array $headers, User $user, string $tokenPrefix)
    {
        $method = $message['method'];
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        if ($method === 'initialize') {
            return $this->initialize($params);
        }

        $modern = $this->isModernRequest($message, $params, $headers);

        switch ($method) {
            case 'server/discover':
                if ($modern) {
                    return $this->decorate($this->discover());
                }
                break;
            case 'tools/list':
                return $this->listTools($user, $modern);
            case 'tools/call':
                return $this->callTool($params, $user, $modern, $tokenPrefix);
            case 'ping':
                if (!$modern) {
                    return new \stdClass();
                }
                break;
        }

        throw new JsonRpcError(
            Protocol::METHOD_NOT_FOUND,
            'Method not found: '.$method,
            null,
            $modern ? 404 : 200
        );
    }

    /**
     * Determine the protocol era from the request headers; in the modern era also
     * verify that the headers agree with the message.
     *
     * @return bool True for the modern era, false for a legacy one.
     *
     * @throws JsonRpcError
     */
    private function isModernRequest(array $message, array $params, array $headers): bool
    {
        $version = isset($headers['mcp-protocol-version']) ? trim($headers['mcp-protocol-version']) : '';

        if ($version === '') {
            throw $this->headerMismatch('Missing MCP-Protocol-Version header');
        }
        if (in_array($version, Protocol::LEGACY_VERSIONS, true)) {
            return false;
        }
        if (!in_array($version, Protocol::MODERN_VERSIONS, true)) {
            throw new JsonRpcError(
                Protocol::UNSUPPORTED_PROTOCOL_VERSION,
                'Unsupported protocol version: '.$version,
                [
                    'supported' => array_merge(Protocol::MODERN_VERSIONS, Protocol::LEGACY_VERSIONS),
                    'requested' => $version,
                ],
                400
            );
        }

        $metaVersion = isset($params['_meta']) && is_array($params['_meta']) && isset($params['_meta'][self::META_PROTOCOL_VERSION])
            ? $params['_meta'][self::META_PROTOCOL_VERSION]
            : null;
        if ($metaVersion !== $version) {
            throw $this->headerMismatch('The protocol version in _meta does not match the MCP-Protocol-Version header');
        }

        $method = $message['method'];
        if (!isset($headers['mcp-method']) || $headers['mcp-method'] !== $method) {
            throw $this->headerMismatch('The Mcp-Method header does not match the request method');
        }

        if ($method === 'tools/call') {
            $name = isset($params['name']) ? $params['name'] : null;
            $headerName = isset($headers['mcp-name']) ? $this->decodeHeaderValue($headers['mcp-name']) : null;
            if ($headerName === null || $headerName !== $name) {
                throw $this->headerMismatch('The Mcp-Name header does not match the tool name');
            }
        }

        return true;
    }

    private function headerMismatch(string $message): JsonRpcError
    {
        return new JsonRpcError(Protocol::HEADER_MISMATCH, $message, null, 400);
    }

    /**
     * Decode a header value that may use the `=?base64?...?=` encoding for
     * values that are not plain ASCII.
     *
     * @return string|null Null if the encoding is malformed.
     */
    private function decodeHeaderValue(string $value)
    {
        if (!preg_match('/^=\?base64\?(.*)\?=$/s', $value, $matches)) {
            return $value;
        }

        $decoded = base64_decode($matches[1], true);

        return $decoded === false ? null : $decoded;
    }

    private function initialize(array $params): array
    {
        $requested = isset($params['protocolVersion']) ? $params['protocolVersion'] : null;
        $version = in_array($requested, Protocol::LEGACY_VERSIONS, true) ? $requested : Protocol::LEGACY_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => $this->serverInfo(),
            'instructions' => Protocol::instructions(),
        ];
    }

    private function discover(): array
    {
        return [
            'supportedVersions' => array_merge(Protocol::MODERN_VERSIONS, Protocol::LEGACY_VERSIONS),
            'capabilities' => ['tools' => new \stdClass()],
            'instructions' => Protocol::instructions(),
            'ttlMs' => 3600000,
            'cacheScope' => 'public',
        ];
    }

    private function listTools(User $user, bool $modern): array
    {
        $tools = array_map(function (Tool $tool) {
            return $tool->definition();
        }, $this->registry->available($user));

        $result = ['tools' => $tools];
        if (!$modern) {
            return $result;
        }

        return $this->decorate($result + ['ttlMs' => 300000, 'cacheScope' => 'private']);
    }

    /**
     * @throws JsonRpcError
     */
    private function callTool(array $params, User $user, bool $modern, string $tokenPrefix): array
    {
        $name = isset($params['name']) ? $params['name'] : null;
        if (!is_string($name) || $name === '') {
            throw new JsonRpcError(Protocol::INVALID_PARAMS, 'Invalid params: "name" must be a non-empty string.');
        }

        $arguments = array_key_exists('arguments', $params) ? $params['arguments'] : [];
        if (!is_array($arguments) || ($arguments !== [] && array_keys($arguments) === range(0, count($arguments) - 1))) {
            throw new JsonRpcError(Protocol::INVALID_PARAMS, 'Invalid params: "arguments" must be an object.');
        }

        $tool = $this->registry->find($name, $user);
        if ($tool === null) {
            throw new JsonRpcError(Protocol::INVALID_PARAMS, 'Unknown tool: '.$name);
        }

        try {
            $data = $this->structuredContent($tool, $tool->handle($arguments, $user));
            $this->audit($tool, $arguments, $user, $tokenPrefix);
            $result = [
                'content' => [['type' => 'text', 'text' => $this->encodeResult($tool, $data)]],
                'structuredContent' => $data,
            ];
        } catch (ToolError $e) {
            $result = [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }

        return $modern ? $this->decorate($result) : $result;
    }

    /**
     * The text block of a tool result.
     *
     * @param array|\stdClass $data
     *
     * @throws JsonRpcError When the result cannot be encoded as JSON (for example text that is not valid UTF-8).
     *                      The tool has already run by then; the client only gets the generic error.
     */
    private function encodeResult(Tool $tool, $data): string
    {
        $text = \Helper::jsonEncodeUtf8($data);
        if (!is_string($text)) {
            \Log::error('[MCP] The result of tool "'.$tool->name().'" could not be encoded as JSON ('.json_last_error_msg().'). The tool had already been executed.');

            throw new JsonRpcError(Protocol::INTERNAL_ERROR, 'Internal server error.', null, 500);
        }

        return $text;
    }

    /**
     * Record a successful call of a tool that changes data in FreeScout's activity log
     * (Manage > Logs, log "mcpforfreescout").
     *
     * Only the ids found in the arguments are stored, never their content: no bodies,
     * subjects, addresses or names. A failure to write the entry is reported to the
     * application log but does not undo the call, which has already taken effect.
     */
    private function audit(Tool $tool, array $arguments, User $user, string $tokenPrefix): void
    {
        $annotations = $tool->annotations();
        if (!empty($annotations['readOnlyHint'])) {
            return;
        }

        try {
            \Helper::log(self::AUDIT_LOG, $tool->name(), [
                'user_id' => $user->id,
                'token_prefix' => $tokenPrefix,
                'arguments' => $this->idArguments($arguments),
            ]);
        } catch (\Throwable $e) {
            \Helper::logException($e, '[MCP]');
        }
    }

    /**
     * The arguments whose name ends in `_id` or `_ids` and whose value is an id or a list of ids.
     */
    private function idArguments(array $arguments): array
    {
        $ids = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || !preg_match('/_ids?$/', $key)) {
                continue;
            }

            if (is_array($value)) {
                $list = array_values(array_filter(array_map([$this, 'idValue'], $value), function ($id) {
                    return $id !== null;
                }));
                if ($list !== []) {
                    $ids[$key] = $list;
                }
            } elseif (($id = $this->idValue($value)) !== null) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $value
     */
    private function idValue($value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Make a tool result usable as `structuredContent`, which must be a JSON object.
     *
     * @return array|\stdClass An empty result becomes an empty object.
     *
     * @throws \UnexpectedValueException If the tool returned a list instead of an associative array.
     */
    private function structuredContent(Tool $tool, array $data)
    {
        if ($data === []) {
            return new \stdClass();
        }
        if (array_keys($data) === range(0, count($data) - 1)) {
            throw new \UnexpectedValueException(
                'Tool "'.$tool->name().'" returned a list; tool results must be associative arrays.'
            );
        }

        return $data;
    }

    /**
     * Add the fields every modern-era result carries.
     */
    private function decorate(array $result): array
    {
        $result['resultType'] = 'complete';
        $result['_meta'] = [self::META_SERVER_INFO => $this->serverInfo()];

        return $result;
    }

    private function serverInfo(): array
    {
        return ['name' => Protocol::SERVER_NAME, 'version' => Protocol::serverVersion()];
    }

    /**
     * @param string|int|float $id
     * @param array|\stdClass  $result
     */
    private function respond($id, $result): McpResponse
    {
        return $this->respondJson(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function respondJson(int $status, array $body): McpResponse
    {
        return new McpResponse($status, $body, ['Content-Type' => 'application/json']);
    }
}

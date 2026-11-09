<?php

namespace Modules\McpForFreescout\Mcp;

/**
 * Transport-neutral response produced by the Server: an HTTP status, an optional
 * JSON-serialisable body and extra headers.
 */
class McpResponse
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array|null
     */
    private $body;

    /**
     * @var array
     */
    private $headers;

    /**
     * @param int        $status  HTTP status code.
     * @param array|null $body    Response document, null when there is no body (202).
     * @param array      $headers Header name => value.
     */
    public function __construct(int $status, ?array $body = null, array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): ?array
    {
        return $this->body;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}

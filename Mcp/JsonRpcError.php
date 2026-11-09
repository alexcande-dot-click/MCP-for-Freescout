<?php

namespace Modules\McpForFreescout\Mcp;

/**
 * A JSON-RPC level error, answered as an `error` object rather than a tool result.
 */
class JsonRpcError extends \Exception
{
    /**
     * @var mixed
     */
    private $data;

    /**
     * @var int
     */
    private $httpStatus;

    /**
     * @param int    $code       JSON-RPC error code, see Protocol.
     * @param string $message    Human readable description.
     * @param mixed  $data       Optional structured details.
     * @param int    $httpStatus HTTP status the transport should answer with.
     */
    public function __construct(int $code, string $message, $data = null, int $httpStatus = 200)
    {
        parent::__construct($message, $code);

        $this->data = $data;
        $this->httpStatus = $httpStatus;
    }

    /**
     * @return mixed
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * @return int
     */
    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Build the JSON-RPC error response for a request.
     *
     * @param string|int|float|null $id Id of the failed request, null if unknown.
     *
     * @return array
     */
    public function toArray($id): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}

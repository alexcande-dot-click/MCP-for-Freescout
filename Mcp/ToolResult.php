<?php

namespace Modules\McpForFreescout\Mcp;

/**
 * The result of a tool that returns more than structured data.
 *
 * `structured` becomes `structuredContent` and the JSON text block, as for a plain
 * array result. `extraContent` holds further MCP content blocks (images, embedded
 * resources) that follow the text block in `content`, in order.
 */
final class ToolResult
{
    /** @var array */
    private $structured;

    /** @var array */
    private $extraContent;

    /**
     * @param array $structured   Structured result, with the same rules as an array returned by Tool::handle().
     * @param array $extraContent Content blocks built with image() or resource().
     */
    public function __construct(array $structured, array $extraContent = [])
    {
        $this->structured = $structured;
        $this->extraContent = array_values($extraContent);
    }

    public function structured(): array
    {
        return $this->structured;
    }

    public function extraContent(): array
    {
        return $this->extraContent;
    }

    /**
     * An image content block.
     *
     * @param string $base64   The image bytes, base64-encoded.
     * @param string $mimeType E.g. "image/png".
     */
    public static function image(string $base64, string $mimeType): array
    {
        return ['type' => 'image', 'data' => $base64, 'mimeType' => $mimeType];
    }

    /**
     * An embedded resource content block with binary contents.
     *
     * @param string $uri      Identifier of the resource.
     * @param string $mimeType E.g. "application/pdf".
     * @param string $base64   The resource bytes, base64-encoded.
     */
    public static function resource(string $uri, string $mimeType, string $base64): array
    {
        return ['type' => 'resource', 'resource' => ['uri' => $uri, 'mimeType' => $mimeType, 'blob' => $base64]];
    }
}

<?php

namespace Modules\McpForFreescout\Mcp;

/**
 * Constants and static facts of the MCP protocol as implemented by this module.
 */
final class Protocol
{
    /**
     * Stateless protocol revisions (server/discover, per-request _meta).
     */
    const MODERN_VERSIONS = ['2026-07-28'];

    /**
     * Revisions negotiated through the initialize handshake, newest first.
     */
    const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18'];

    const SERVER_NAME = 'mcp-for-freescout';

    const PARSE_ERROR = -32700;
    const INVALID_REQUEST = -32600;
    const METHOD_NOT_FOUND = -32601;
    const INVALID_PARAMS = -32602;
    const INTERNAL_ERROR = -32603;
    const HEADER_MISMATCH = -32020;
    const UNSUPPORTED_PROTOCOL_VERSION = -32022;
    const UNAUTHORIZED = -32001;

    /**
     * @var string|null
     */
    private static $serverVersion;

    /**
     * The module version, read from the module's own module.json so it does not
     * depend on the module being active.
     *
     * @return string
     */
    public static function serverVersion(): string
    {
        if (self::$serverVersion === null) {
            $json = @file_get_contents(dirname(__DIR__).'/module.json');
            $manifest = $json !== false ? json_decode($json, true) : null;

            self::$serverVersion = is_array($manifest) && isset($manifest['version'])
                ? (string) $manifest['version']
                : '0.0.0';
        }

        return self::$serverVersion;
    }

    /**
     * Usage instructions sent to the client when it connects.
     *
     * @return string
     */
    public static function instructions(): string
    {
        return 'This server gives you access to a FreeScout help desk. '
            .'You act as the FreeScout user who owns the access token, with exactly that user\'s permissions. '
            .'Replies and new emails you create are saved as drafts for human review; '
            .'only the send_draft tool sends an email. '
            .'Conversations can only be moved to the trash; nothing is ever permanently deleted.';
    }
}

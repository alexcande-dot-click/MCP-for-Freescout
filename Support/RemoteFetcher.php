<?php

namespace Modules\McpForFreescout\Support;

use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Downloads a file from a link given by an agent.
 */
interface RemoteFetcher
{
    /**
     * Download the file at a link.
     *
     * @param string $url      The http or https link.
     * @param int    $maxBytes The largest file accepted, in bytes.
     *
     * @return array ['bytes' => string, 'mime_type' => ?string]
     *
     * @throws ToolError When the link is refused or the download fails.
     */
    public function fetch(string $url, int $maxBytes): array;
}

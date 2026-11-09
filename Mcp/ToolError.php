<?php

namespace Modules\McpForFreescout\Mcp;

/**
 * Thrown by a tool when the call cannot be fulfilled for a reason the model
 * should see and may correct (bad argument, missing permission, unknown record).
 * The message is returned to the model as an error tool result.
 */
class ToolError extends \Exception
{
}

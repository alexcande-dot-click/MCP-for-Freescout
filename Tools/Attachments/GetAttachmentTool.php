<?php

namespace Modules\McpForFreescout\Tools\Attachments;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolResult;
use Modules\McpForFreescout\Support\Attachments;

class GetAttachmentTool extends Tool
{
    /** Largest image, in bytes, that is returned inline: 3.5 MB. */
    const MAX_INLINE_IMAGE_SIZE = 3670016;

    const INLINE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function name(): string
    {
        return 'get_attachment';
    }

    public function description(): string
    {
        return 'Get one file (attachment or embedded image) of a message, by the id that get_conversation lists. '
            .'Returns its details, its conversation_id and thread_id, and `url`, a download link that works without login. '
            .'Small PNG, JPEG, GIF and WebP images are also returned as an image you can look at. '
            .'For a PDF or other document, hand `url` to the PDF or document tool of your client; '
            .'set include_content to also receive the bytes as an embedded resource, within the size limit of this server.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'attachment_id'   => ['type' => 'integer', 'description' => 'File id, from the attachments of get_conversation.'],
                'include_content' => ['type' => 'boolean', 'description' => 'Also return the file as an embedded resource (default false).'],
            ],
            'required'             => ['attachment_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user)
    {
        $attachment = Attachments::findVisible($this->requireInt($arguments, 'attachment_id'), $user);
        $includeContent = $this->optionalBool($arguments, 'include_content', false);

        $structured = Attachments::present($attachment);
        $structured['thread_id'] = (int) $attachment->thread_id;
        $structured['conversation_id'] = (int) $attachment->thread->conversation_id;

        $size = (int) $attachment->size;
        $mimeType = strtolower((string) $structured['mime_type']);
        $inline = in_array($mimeType, self::INLINE_IMAGE_TYPES, true) && $size <= self::MAX_INLINE_IMAGE_SIZE;

        if ($includeContent && $size > Attachments::maxSize()) {
            throw new ToolError('File is larger than '.Attachments::maxSize().' bytes; download it from '.$structured['url']);
        }

        $blocks = [];
        if ($inline || $includeContent) {
            $base64 = base64_encode(Attachments::contents($attachment));
            if ($inline) {
                $blocks[] = ToolResult::image($base64, $mimeType);
            }
            if ($includeContent) {
                $blocks[] = ToolResult::resource($structured['url'], $mimeType, $base64);
            }
        }

        return new ToolResult($structured, $blocks);
    }
}

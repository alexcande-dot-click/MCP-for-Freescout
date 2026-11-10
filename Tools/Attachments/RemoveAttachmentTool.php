<?php

namespace Modules\McpForFreescout\Tools\Attachments;

use App\Attachment;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Attachments;
use Modules\McpForFreescout\Tools\Drafts\DraftTool;

class RemoveAttachmentTool extends DraftTool
{
    public function name(): string
    {
        return 'remove_attachment';
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true];
    }

    public function description(): string
    {
        return 'Remove one file from your own unsent draft and delete it for good. Nothing is sent. '
            .'Give the file id from the attachments of add_attachments or get_conversation. '
            .'Images embedded in the text cannot be removed here; edit the draft in FreeScout for that. '
            .'Returns the files that remain on the draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'draft_id'      => ['type' => 'integer', 'description' => 'Draft id, as returned by reply_to_conversation or create_conversation.'],
                'attachment_id' => ['type' => 'integer', 'description' => 'Id of the file on the draft.'],
            ],
            'required'             => ['draft_id', 'attachment_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $draft = $this->findOwnDraft($this->requireInt($arguments, 'draft_id'), $user);
        $attachmentId = $this->requireInt($arguments, 'attachment_id');

        $attachment = Attachment::find($attachmentId);
        if (!$attachment || (int) $attachment->thread_id !== (int) $draft->id) {
            throw new ToolError('Attachment not found on this draft.');
        }
        if ($attachment->embedded) {
            throw new ToolError('Embedded images are part of the text; edit the draft in FreeScout to remove it.');
        }

        // Deletes the stored file and the row, and clears the has_attachments flag of the draft
        // (and of its conversation) when this was the last file.
        Attachment::deleteAttachments(collect([$attachment]));

        $draft = $draft->fresh();

        return [
            'draft_id'        => (int) $draft->id,
            'conversation_id' => (int) $draft->conversation_id,
            'attachments'     => Attachments::ofThread($draft),
        ];
    }
}

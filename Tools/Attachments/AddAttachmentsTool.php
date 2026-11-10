<?php

namespace Modules\McpForFreescout\Tools\Attachments;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Attachments;
use Modules\McpForFreescout\Support\FileInputs;
use Modules\McpForFreescout\Tools\Drafts\DraftTool;

class AddAttachmentsTool extends DraftTool
{
    public function name(): string
    {
        return 'add_attachments';
    }

    public function description(): string
    {
        return 'Attach more files to your own unsent draft. Nothing is sent. '
            .'The text, recipients, sender address, choices and files of the draft stay as they are. '
            .'Each file comes from a link, base64 content or an existing attachment; the draft with all its files must stay within the maximum message size. '
            .'Returns the files of the draft. Remove one again with remove_attachment.';
    }

    public function inputSchema(): array
    {
        $files = FileInputs::schema('Files to attach to the draft. Each one comes from a link, base64 content or an existing attachment.');
        $files['minItems'] = 1;

        return [
            'type'                 => 'object',
            'properties'           => [
                'draft_id'    => ['type' => 'integer', 'description' => 'Draft id, as returned by reply_to_conversation or create_conversation.'],
                'attachments' => $files,
            ],
            'required'             => ['draft_id', 'attachments'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $draft = $this->findOwnDraft($this->requireInt($arguments, 'draft_id'), $user);
        if (empty($arguments['attachments'])) {
            throw new ToolError('Argument "attachments" is required and must hold at least one file.');
        }

        // The files are stored last, after everything that can be refused has been checked.
        $files = $this->optionalFiles($arguments, $user);
        $saved = $this->saveDraft($user, $this->currentDraftParams($draft), null, $files);

        return [
            'draft_id'        => (int) $saved->id,
            'conversation_id' => (int) $saved->conversation_id,
            'attachments'     => Attachments::ofThread($saved),
        ];
    }
}

<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Presenter;

class UpdateSavedReplyTool extends SavedReplyTool
{
    public function name(): string
    {
        return 'update_saved_reply';
    }

    public function description(): string
    {
        return 'Change the name, text, parent or global flag of a saved reply. Give at least one of them; its attached files stay as they are. '
            .'Needs the permission to edit saved replies and access to the mailbox of the saved reply. Returns the updated saved reply.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'saved_reply_id' => ['type' => 'integer', 'description' => 'Saved reply id, from list_saved_replies.'],
                'name'           => ['type' => 'string', 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'New name; unique within the mailbox.'],
                'text'           => ['type' => 'string', 'description' => 'New text, plain text or HTML.'],
                'parent_id'      => ['type' => 'integer', 'description' => 'Id of a saved reply of the same mailbox to put this one under (a category), or 0 for the top level.'],
                'global'         => ['type' => 'boolean', 'description' => 'Whether the saved reply is offered in all mailboxes.'],
            ],
            'required'             => ['saved_reply_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $reply = $this->findReply($this->requireInt($arguments, 'saved_reply_id'), $user);
        $this->assertCanEdit($reply->mailbox, $user);

        $name = $this->optionalString($arguments, 'name');
        $text = $this->optionalString($arguments, 'text');
        $parentId = $this->optionalInt($arguments, 'parent_id');
        $global = $this->optionalBool($arguments, 'global');
        if ($name === null && $text === null && $parentId === null && $global === null) {
            throw new ToolError('Give at least one of name, text, parent_id or global.');
        }

        $mailboxId = (int) $reply->mailbox_id;
        if ($name !== null) {
            $reply->name = $this->validName($name, $mailboxId, $reply);
        }
        if ($text !== null) {
            $reply->text = $this->sanitisedText($text);
        }
        if ($parentId !== null) {
            $reply->parent_saved_reply_id = $this->validParent($parentId, $mailboxId, $reply);
        }
        if ($global !== null) {
            $reply->global = $global;
        }
        $reply->save();

        return Presenter::savedReply($reply);
    }
}

<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\User;

class DeleteSavedReplyTool extends SavedReplyTool
{
    public function name(): string
    {
        return 'delete_saved_reply';
    }

    public function description(): string
    {
        return 'Permanently delete a saved reply together with its attached files. Saved replies below it move to the top level. '
            .'Drafts that chose it must get another one with update_draft before they can be sent. '
            .'Needs the permission to edit saved replies and access to the mailbox of the saved reply.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'saved_reply_id' => ['type' => 'integer', 'description' => 'Saved reply id, from list_saved_replies.'],
            ],
            'required'             => ['saved_reply_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $reply = $this->findReply($this->requireInt($arguments, 'saved_reply_id'), $user);
        $this->assertCanEdit($reply->mailbox, $user);

        $reply->deleteSavedReply();

        return ['deleted' => true, 'saved_reply_id' => (int) $reply->id];
    }
}

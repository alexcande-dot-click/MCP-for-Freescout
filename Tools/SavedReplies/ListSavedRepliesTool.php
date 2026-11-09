<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\User;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\SavedReplies\Entities\SavedReply;

class ListSavedRepliesTool extends SavedReplyTool
{
    public function name(): string
    {
        return 'list_saved_replies';
    }

    public function description(): string
    {
        return 'List the saved replies the reply form of a mailbox offers, as a tree of categories and replies: those of the mailbox '
            .'and the global ones of other mailboxes. Texts are left out; read one with get_saved_reply. '
            .'The id can be passed as saved_reply_id to reply_to_conversation, create_conversation and update_draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Mailbox id, from list_mailboxes.'],
            ],
            'required'             => ['mailbox_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailbox = Lookup::mailbox($this->requireInt($arguments, 'mailbox_id'), $user);

        $tree = SavedReply::listToTree(SavedReplies::offeredIn($mailbox));

        return ['mailbox_id' => (int) $mailbox->id, 'saved_replies' => Presenter::savedReplyTree($tree)];
    }
}

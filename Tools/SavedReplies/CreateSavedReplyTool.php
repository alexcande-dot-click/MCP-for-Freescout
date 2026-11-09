<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\User;
use Modules\McpForFreescout\Support\Presenter;
use Modules\SavedReplies\Entities\SavedReply;

class CreateSavedReplyTool extends SavedReplyTool
{
    public function name(): string
    {
        return 'create_saved_reply';
    }

    public function description(): string
    {
        return 'Add a saved reply to a mailbox. The text may be plain text or HTML and can use FreeScout variables such as {%customer.firstName%}. '
            .'Files cannot be attached with this tool. Needs the permission to edit saved replies and access to the mailbox. Returns the new saved reply.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Mailbox id, from list_mailboxes.'],
                'name'       => ['type' => 'string', 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'Name shown in the saved replies list; unique within the mailbox.'],
                'text'       => ['type' => 'string', 'description' => 'Saved reply text, plain text or HTML.'],
                'parent_id'  => ['type' => 'integer', 'description' => 'Id of a saved reply of the same mailbox to put this one under (a category); 0 or absent for the top level.'],
                'global'     => ['type' => 'boolean', 'description' => 'Offer the saved reply in all mailboxes. Default false.'],
            ],
            'required'             => ['mailbox_id', 'name', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailbox = $this->mailboxToEdit($this->requireInt($arguments, 'mailbox_id'), $user);
        $name = $this->validName($this->requireString($arguments, 'name'), (int) $mailbox->id);
        $text = $this->sanitisedText($this->requireString($arguments, 'text'));
        $parentId = $this->validParent($this->optionalInt($arguments, 'parent_id', 0), (int) $mailbox->id);

        $reply = new SavedReply();
        $reply->mailbox_id = $mailbox->id;
        $reply->name = $name;
        $reply->text = $text;
        $reply->user_id = $user->id;
        $reply->parent_saved_reply_id = $parentId;
        $reply->global = (bool) $this->optionalBool($arguments, 'global', false);
        $reply->save();

        return Presenter::savedReply($reply);
    }
}

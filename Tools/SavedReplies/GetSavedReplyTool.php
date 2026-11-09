<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\User;
use Modules\McpForFreescout\Support\Presenter;

class GetSavedReplyTool extends SavedReplyTool
{
    public function name(): string
    {
        return 'get_saved_reply';
    }

    public function description(): string
    {
        return 'Get a saved reply with its stored text (variables such as {%customer.firstName%} are not replaced) and the files attached to it.';
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

    public function handle(array $arguments, User $user): array
    {
        return Presenter::savedReply($this->findReply($this->requireInt($arguments, 'saved_reply_id'), $user));
    }
}

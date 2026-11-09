<?php

namespace Modules\McpForFreescout\Tools\Mailboxes;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class GetMailboxTool extends Tool
{
    public function name(): string
    {
        return 'get_mailbox';
    }

    public function description(): string
    {
        return 'Get one mailbox: email address, reply-from aliases, signature and your permissions on it.';
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

        return Presenter::mailbox($mailbox, $user);
    }
}

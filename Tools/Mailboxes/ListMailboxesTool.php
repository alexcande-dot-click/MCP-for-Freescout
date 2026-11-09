<?php

namespace Modules\McpForFreescout\Tools\Mailboxes;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Presenter;

class ListMailboxesTool extends Tool
{
    public function name(): string
    {
        return 'list_mailboxes';
    }

    public function description(): string
    {
        return 'List the mailboxes you can access, with their email addresses, reply-from aliases, signatures and your permissions. '
            .'Start here: the mailbox ids are needed by most other tools.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailboxes = [];
        foreach ($user->mailboxesCanView() as $mailbox) {
            $mailboxes[] = Presenter::mailbox($mailbox, $user);
        }

        return ['mailboxes' => $mailboxes];
    }
}

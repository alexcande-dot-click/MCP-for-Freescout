<?php

namespace Modules\McpForFreescout\Tools\Directory;

use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class ListUsersTool extends Tool
{
    public function name(): string
    {
        return 'list_users';
    }

    public function description(): string
    {
        return 'List the active FreeScout users you can see, for example to pick an assignee. '
            .'Pass mailbox_id to list only the users who have access to that mailbox.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Only users with access to this mailbox (id from list_mailboxes).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailboxId = $this->optionalInt($arguments, 'mailbox_id');

        if ($mailboxId !== null) {
            $users = Lookup::mailbox($mailboxId, $user)->usersHavingAccess();
        } else {
            $users = $user->whichUsersCanView();
        }

        $presented = [];
        foreach ($users as $listed) {
            if ($listed->isActive()) {
                $presented[] = Presenter::user($listed);
            }
        }

        return ['users' => $presented];
    }
}

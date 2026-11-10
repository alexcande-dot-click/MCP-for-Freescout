<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\ConversationPage;
use Modules\McpForFreescout\Support\ConversationStatuses;
use Modules\McpForFreescout\Support\Lookup;

class SearchConversationsTool extends Tool
{
    public function name(): string
    {
        return 'search_conversations';
    }

    public function description(): string
    {
        return 'Search published conversations in the mailboxes you can access, the way the FreeScout search box does: '
            .'by subject, customer name or email, conversation number, or the text, sender and recipients of any message. '
            .'Newest reply first.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'query'      => ['type' => 'string', 'description' => 'Text to look for.'],
                'mailbox_id' => ['type' => 'integer', 'description' => 'Only this mailbox (id from list_mailboxes). Default: all your mailboxes.'],
                'status'     => ['type' => 'string', 'enum' => array_keys(ConversationStatuses::BY_NAME), 'description' => 'Only conversations with this status.'],
                'page'       => ['type' => 'integer', 'description' => 'Page number, starting at 1.', 'minimum' => 1, 'maximum' => ConversationPage::MAX_PAGE],
                'per_page'   => [
                    'type'        => 'integer',
                    'description' => 'Conversations per page (default '.ConversationPage::DEFAULT_PER_PAGE.', at most '.ConversationPage::MAX_PER_PAGE.').',
                    'minimum'     => 1,
                    'maximum'     => ConversationPage::MAX_PER_PAGE,
                ],
            ],
            'required'             => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $query = trim($this->requireString($arguments, 'query'));
        $mailboxId = $this->optionalInt($arguments, 'mailbox_id');
        $status = $this->optionalString($arguments, 'status');
        $page = $this->optionalIntInRange($arguments, 'page', 1, 1, ConversationPage::MAX_PAGE);
        $perPage = $this->optionalIntInRange($arguments, 'per_page', ConversationPage::DEFAULT_PER_PAGE, 1, ConversationPage::MAX_PER_PAGE);

        $filters = ['state' => [Conversation::STATE_PUBLISHED]];

        if ($mailboxId !== null) {
            // Core silently widens a search to all mailboxes when the one asked for is off limits.
            $filters['mailbox'] = Lookup::mailbox($mailboxId, $user)->id;
        }
        if ($status !== null) {
            if (!isset(ConversationStatuses::BY_NAME[$status])) {
                throw new ToolError('Argument "status" must be one of: '.implode(', ', array_keys(ConversationStatuses::BY_NAME)).'.');
            }
            $filters['status'] = [ConversationStatuses::BY_NAME[$status]];
        }
        if ($user->canSeeOnlyAssignedConversations()) {
            $filters['assigned'] = $user->id;
        }

        return ConversationPage::present(Conversation::search($query, $filters, $user), $page, $perPage, $user);
    }
}

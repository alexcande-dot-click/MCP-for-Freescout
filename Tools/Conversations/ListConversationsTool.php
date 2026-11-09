<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\Folder;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\ConversationPage;
use Modules\McpForFreescout\Support\ConversationStatuses;
use Modules\McpForFreescout\Support\Lookup;

class ListConversationsTool extends Tool
{
    const FOLDERS = [
        'unassigned' => Folder::TYPE_UNASSIGNED,
        'mine'       => Folder::TYPE_MINE,
        'assigned'   => Folder::TYPE_ASSIGNED,
        'drafts'     => Folder::TYPE_DRAFTS,
        'closed'     => Folder::TYPE_CLOSED,
        'spam'       => Folder::TYPE_SPAM,
        'deleted'    => Folder::TYPE_DELETED,
        'starred'    => Folder::TYPE_STARRED,
    ];

    public function name(): string
    {
        return 'list_conversations';
    }

    public function description(): string
    {
        return 'List conversations of the mailboxes you can access, newest reply first, with optional filters. '
            .'Without a folder, published conversations are listed; use folder "drafts" for your own drafts '
            .'and "deleted" for deleted conversations. A folder needs a mailbox_id.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Only this mailbox (id from list_mailboxes). Default: all your mailboxes.'],
                'folder'     => [
                    'type'        => 'string',
                    'enum'        => array_keys(self::FOLDERS),
                    'description' => 'Mailbox folder, as in the FreeScout sidebar. Requires mailbox_id.',
                ],
                'status'     => ['type' => 'string', 'enum' => array_keys(ConversationStatuses::BY_NAME), 'description' => 'Only conversations with this status.'],
                'assignee'   => [
                    'type'        => ['integer', 'string'],
                    'description' => 'A user id, "me" for yourself or "none" for unassigned conversations.',
                ],
                'tag'        => ['type' => 'string', 'description' => 'Only conversations with this tag. Available when the Tags module is active.'],
                'page'       => ['type' => 'integer', 'description' => 'Page number, starting at 1.', 'minimum' => 1, 'maximum' => ConversationPage::MAX_PAGE],
                'per_page'   => [
                    'type'        => 'integer',
                    'description' => 'Conversations per page (default '.ConversationPage::DEFAULT_PER_PAGE.', at most '.ConversationPage::MAX_PER_PAGE.').',
                    'minimum'     => 1,
                    'maximum'     => ConversationPage::MAX_PER_PAGE,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailboxId = $this->optionalInt($arguments, 'mailbox_id');
        $folder = $this->optionalChoice($arguments, 'folder', self::FOLDERS);
        $status = $this->optionalChoice($arguments, 'status', ConversationStatuses::BY_NAME);
        $assignee = $this->assignee($arguments);
        $tag = $this->tag($arguments);
        $page = $this->optionalIntInRange($arguments, 'page', 1, 1, ConversationPage::MAX_PAGE);
        $perPage = $this->optionalIntInRange($arguments, 'per_page', ConversationPage::DEFAULT_PER_PAGE, 1, ConversationPage::MAX_PER_PAGE);

        if ($folder !== null && $mailboxId === null) {
            throw new ToolError('Argument "mailbox_id" is required when "folder" is given.');
        }

        $query = $folder !== null
            ? $this->folderQuery($mailboxId, $folder, $user)
            : $this->mailboxesQuery($mailboxId, $user);

        if ($status !== null) {
            $query->where('conversations.status', $status);
        }
        if ($assignee === 'none') {
            $query->whereNull('conversations.user_id');
        } elseif ($assignee !== null) {
            $query->where('conversations.user_id', $assignee === 'me' ? $user->id : $assignee);
        }
        if ($tag !== null) {
            $query->whereIn('conversations.id', function ($tagged) use ($tag) {
                $tagged->select('conversation_tag.conversation_id')
                    ->from('conversation_tag')
                    ->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
                    ->where('tags.name', $tag);
            });
        }
        if ($folder !== Folder::TYPE_DRAFTS && $user->canSeeOnlyAssignedConversations()) {
            $query->where('conversations.user_id', $user->id);
        }

        return ConversationPage::present($query, $page, $perPage);
    }

    /**
     * Published conversations of one mailbox, or of every mailbox the user can view.
     */
    private function mailboxesQuery(?int $mailboxId, User $user)
    {
        $mailboxIds = $mailboxId !== null
            ? [Lookup::mailbox($mailboxId, $user)->id]
            : $user->mailboxesIdsCanView();

        return Conversation::select('conversations.*')
            ->whereIn('conversations.mailbox_id', $mailboxIds)
            ->where('conversations.state', Conversation::STATE_PUBLISHED);
    }

    /**
     * The conversations of a mailbox folder, as the FreeScout UI lists them.
     *
     * Core's drafts folder holds every user's drafts; it is narrowed here to the
     * caller's own, so the query is built directly instead of through core.
     */
    private function folderQuery(int $mailboxId, int $folderType, User $user)
    {
        $mailbox = Lookup::mailbox($mailboxId, $user);

        $folder = Folder::where('mailbox_id', $mailbox->id)
            ->where('type', $folderType)
            ->when(in_array($folderType, Folder::$personal_types, true), function ($query) use ($user) {
                $query->where('user_id', $user->id);
            })
            ->first();
        if (!$folder) {
            throw new ToolError('Folder not found.');
        }

        if ($folderType !== Folder::TYPE_DRAFTS) {
            return Conversation::getQueryByFolder($folder, $user->id);
        }

        return Conversation::select('conversations.*')
            ->whereIn('conversations.id', function ($filed) use ($folder) {
                $filed->select('conversation_id')->from('conversation_folder')->where('folder_id', $folder->id);
            })
            ->whereIn('conversations.id', function ($drafted) use ($user) {
                $drafted->select('conversation_id')
                    ->from('threads')
                    ->where('state', Thread::STATE_DRAFT)
                    ->where('created_by_user_id', $user->id);
            });
    }

    /**
     * Read an optional string argument that must be one of the keys of $choices.
     *
     * @return int|null The value mapped to the chosen key.
     *
     * @throws ToolError When the argument is not one of the keys.
     */
    private function optionalChoice(array $arguments, string $key, array $choices): ?int
    {
        $value = $this->optionalString($arguments, $key);
        if ($value === null) {
            return null;
        }
        if (!isset($choices[$value])) {
            throw new ToolError('Argument "'.$key.'" must be one of: '.implode(', ', array_keys($choices)).'.');
        }

        return $choices[$value];
    }

    /**
     * @return int|string|null A user id, "me", "none", or null when absent.
     *
     * @throws ToolError When the argument is none of those.
     */
    private function assignee(array $arguments)
    {
        $value = $this->optionalString($arguments, 'assignee');
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === 'me' || $value === 'none') {
            return $value;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false) {
            throw new ToolError('Argument "assignee" must be a user id, "me" or "none".');
        }

        return $id;
    }

    /**
     * @throws ToolError When a tag is given but the Tags module is not active.
     */
    private function tag(array $arguments): ?string
    {
        $tag = $this->optionalString($arguments, 'tag');
        $name = $tag === null ? '' : self::normalizeTagName($tag);
        if ($name === '') {
            return null;
        }
        if (!CompanionModules::isActive(CompanionModules::TAGS)) {
            throw new ToolError('The tag filter requires the Tags module.');
        }

        return $name;
    }

    /**
     * Normalise a tag name the way the Tags module stores it: surrounding
     * whitespace removed, lower case. This keeps the filter case-insensitive on
     * databases whose string comparison is case-sensitive.
     */
    public static function normalizeTagName(string $name): string
    {
        return mb_strtolower(preg_replace('/^\s+|\s+$/u', '', trim($name)));
    }
}

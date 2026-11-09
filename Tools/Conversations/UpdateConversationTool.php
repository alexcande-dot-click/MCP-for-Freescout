<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\ConversationStatuses;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class UpdateConversationTool extends Tool
{
    /**
     * Longest subject FreeScout stores.
     */
    const MAX_SUBJECT_LENGTH = 998;

    public function name(): string
    {
        return 'update_conversation';
    }

    public function description(): string
    {
        return 'Change the status, the assignee and/or the subject of a conversation, recorded in its history as you. '
            .'Give at least one of status, assignee_id and subject; values the conversation already has are left as they are. '
            .'Returns the updated conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'status'          => ['type' => 'string', 'enum' => array_keys(ConversationStatuses::BY_NAME), 'description' => 'New status.'],
                'assignee_id'     => [
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'description' => 'User id of the new assignee (from list_users; must be an active user with access to the mailbox), or 0 to unassign.',
                ],
                'subject'         => ['type' => 'string', 'maxLength' => self::MAX_SUBJECT_LENGTH, 'description' => 'New subject.'],
            ],
            'required'             => ['conversation_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');
        $status = $this->status($arguments);
        $assigneeId = $this->optionalInt($arguments, 'assignee_id');
        $subject = $this->subject($arguments);

        if ($status === null && $assigneeId === null && $subject === null) {
            throw new ToolError('Give at least one of status, assignee_id or subject.');
        }

        $conversation = Lookup::conversation($conversationId, $user);
        if (!$user->can('update', $conversation)) {
            throw new ToolError('You are not allowed to update this conversation.');
        }
        if ($assigneeId !== null) {
            $this->assertCanBeAssigned($conversation, $assigneeId);
        }

        if ($status !== null && (int) $conversation->status !== $status) {
            CoreActions::conversationAjax($user, [
                'action'          => 'conversation_change_status',
                'conversation_id' => $conversation->id,
                'status'          => $status,
            ]);
        }
        if ($assigneeId !== null && (int) $conversation->user_id !== $assigneeId) {
            CoreActions::conversationAjax($user, [
                'action'          => 'conversation_change_user',
                'conversation_id' => $conversation->id,
                // Core expects -1 for "unassigned".
                'user_id'         => $assigneeId === 0 ? -1 : $assigneeId,
            ]);
        }
        if ($subject !== null && $conversation->subject !== $subject) {
            CoreActions::conversationAjax($user, [
                'action'          => 'update_subject',
                'conversation_id' => $conversation->id,
                'value'           => $subject,
            ]);
        }

        return Presenter::conversationSummary($conversation->fresh());
    }

    /**
     * @return int|null FreeScout's status code, or null when the argument is absent.
     *
     * @throws ToolError When the argument is not a known status name.
     */
    private function status(array $arguments): ?int
    {
        $name = $this->optionalString($arguments, 'status');
        if ($name === null) {
            return null;
        }
        if (!isset(ConversationStatuses::BY_NAME[$name])) {
            throw new ToolError('Argument "status" must be one of: '.implode(', ', array_keys(ConversationStatuses::BY_NAME)).'.');
        }

        return ConversationStatuses::BY_NAME[$name];
    }

    /**
     * @return string|null The trimmed subject, or null when the argument is absent.
     *
     * @throws ToolError When the subject is blank or too long.
     */
    private function subject(array $arguments): ?string
    {
        $subject = $this->optionalString($arguments, 'subject');
        if ($subject === null) {
            return null;
        }

        $subject = trim($subject);
        if ($subject === '') {
            throw new ToolError('Argument "subject" must not be empty.');
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new ToolError('Argument "subject" must be at most '.self::MAX_SUBJECT_LENGTH.' characters long.');
        }

        return $subject;
    }

    /**
     * Check the whole request before any change is made, so a bad assignee does
     * not leave a conversation half updated.
     *
     * @throws ToolError When the assignee is neither 0 nor an active user with access to the mailbox.
     */
    private function assertCanBeAssigned(Conversation $conversation, int $assigneeId): void
    {
        if ($assigneeId === 0) {
            return;
        }

        $assignee = $assigneeId > 0 ? User::find($assigneeId) : null;
        if (!$assignee || !$assignee->isActive() || !$conversation->mailbox->userHasAccess($assignee->id)) {
            throw new ToolError('Argument "assignee_id" must be 0 (unassign) or the id of an active user with access to the mailbox.');
        }
    }
}

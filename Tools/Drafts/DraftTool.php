<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\ConversationStatuses;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\McpForFreescout\Support\Signatures;
use Modules\SavedReplies\Entities\SavedReply;

/**
 * Base class of the tools that create, change or discard drafts.
 *
 * These tools never send anything: they post exactly what the web UI's draft
 * autosave posts, so the result is always a thread in the draft state. Sending
 * is left to send_draft.
 */
abstract class DraftTool extends Tool
{
    /**
     * Longest subject FreeScout stores.
     */
    const MAX_SUBJECT_LENGTH = 998;

    /**
     * The status names that can be chosen to apply when the draft is sent.
     */
    const STATUS_AFTER_SEND = ['active', 'pending', 'closed'];

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false];
    }

    /**
     * Schema of an email recipient list argument.
     */
    protected function recipientSchema(string $description): array
    {
        return [
            'type'        => 'array',
            'items'       => ['type' => 'string'],
            'description' => $description,
        ];
    }

    /**
     * Schema of the arguments that choose what happens when the draft is sent.
     *
     * @param bool $clearable Whether an explicit null removes a stored choice (update_draft).
     */
    protected function choiceSchemas(bool $clearable = false): array
    {
        $null = $clearable ? ' Pass null to clear the choice stored on the draft.' : '';
        $nullable = function (string $type) use ($clearable) {
            return $clearable ? [$type, 'null'] : $type;
        };

        return [
            'signature_id'        => [
                'type'        => $nullable('integer'),
                'description' => 'Id of a custom signature of the draft mailbox to use on send (from list_signatures). Requires the Custom Mailbox Signatures module.'.$null,
            ],
            'status_after_send'   => [
                'type'        => $nullable('string'),
                'enum'        => $clearable ? array_merge(self::STATUS_AFTER_SEND, [null]) : self::STATUS_AFTER_SEND,
                'description' => 'Conversation status to set when the draft is sent.'.$null,
            ],
            'assignee_after_send' => [
                'type'        => $nullable('integer'),
                'minimum'     => 0,
                'description' => 'User id (from list_users; must have access to the mailbox) to assign when the draft is sent, or 0 to unassign.'.$null,
            ],
            'saved_reply_id'      => [
                'type'        => $nullable('integer'),
                'description' => 'Id of a saved reply of the draft mailbox, or a global one (from list_saved_replies). Without body its text becomes the body, with the variables replaced like the reply form does; its files are copied to the draft. Requires the Saved Replies module.'
                    .($clearable ? ' Pass null to forget the saved reply choice; the body and files of the draft stay as they are.' : ''),
            ],
        ];
    }

    /**
     * Whether the caller passed an explicit JSON null for a choice, which asks to clear it.
     */
    protected function clearsChoice(array $arguments, string $key): bool
    {
        return array_key_exists($key, $arguments) && $arguments[$key] === null;
    }

    /**
     * Read a list of plain email addresses. Names such as "Jo <jo@example.com>"
     * are refused, because core would silently create customers from them.
     *
     * @return string[]|null Lower-cased, de-duplicated addresses, or null when the argument is absent.
     *
     * @throws ToolError When an entry is not an email address.
     */
    protected function recipients(array $arguments, string $key): ?array
    {
        $list = $this->optionalStringList($arguments, $key);
        if ($list === null) {
            return null;
        }

        $emails = [];
        foreach ($list as $entry) {
            $entry = trim($entry);
            $email = preg_match('/^[^@\s<>,;"]+@[^@\s<>,;"]+$/', $entry) ? \App\Email::sanitizeEmail($entry) : false;
            if (!$email) {
                throw new ToolError('Argument "'.$key.'" contains an invalid email address: "'.$entry.'".');
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    /**
     * The sender address to post for the draft.
     *
     * @param string|null $current The address the draft has now, kept when the argument is absent.
     *
     * @return string|null The alias to store on the draft, or null for the mailbox's own address.
     *
     * @throws ToolError When the address is not an alias the mailbox may reply from.
     */
    protected function fromAlias(array $arguments, Mailbox $mailbox, ?string $current): ?string
    {
        $alias = $this->optionalString($arguments, 'from_alias');
        if ($alias === null) {
            return $current;
        }

        $allowed = array_keys($mailbox->getAliases(true, true));
        if (!$allowed) {
            throw new ToolError('from_alias cannot be used: replying from aliases is disabled for this mailbox.');
        }

        $alias = mb_strtolower(trim($alias), 'UTF-8');
        if (!in_array($alias, array_map('mb_strtolower', $allowed), true)) {
            throw new ToolError('from_alias must be one of: '.implode(', ', $allowed).'.');
        }

        // The web form posts an empty value for the mailbox's own address.
        return $alias === mb_strtolower($mailbox->email, 'UTF-8') ? null : $alias;
    }

    /**
     * Read and check the arguments that choose what happens on send.
     *
     * @param SavedReply|null $savedReply The saved reply checked by savedReply(), if the caller gave one.
     * @param bool            $clearable  Whether an explicit null clears a choice (update_draft); elsewhere null means "not given".
     *
     * @return array Choices to give to DraftChoices::store(); only those the caller gave, with null for the ones to clear.
     *               Clearing needs no companion module, so a choice that went stale can always be removed.
     *
     * @throws ToolError When a choice is invalid or its companion module is not active.
     */
    protected function choices(array $arguments, Mailbox $mailbox, SavedReply $savedReply = null, bool $clearable = false): array
    {
        $choices = [];
        if ($clearable) {
            $cleared = ['signature_id' => 'signature_id', 'status_after_send' => 'status', 'assignee_after_send' => 'assignee_id', 'saved_reply_id' => 'saved_reply_id'];
            foreach ($cleared as $argument => $choice) {
                if ($this->clearsChoice($arguments, $argument)) {
                    $choices[$choice] = null;
                }
            }
        }

        $signatureId = $this->optionalInt($arguments, 'signature_id');
        if ($signatureId !== null) {
            if (!CompanionModules::isActive(CompanionModules::CUSTOM_SIGNATURES)) {
                throw new ToolError('signature_id requires the Custom Mailbox Signatures module.');
            }
            if (!Signatures::belongsToMailbox($signatureId, $mailbox)) {
                throw new ToolError('signature_id does not belong to this mailbox.');
            }
            $choices['signature_id'] = $signatureId;
        }

        $statusName = $this->optionalString($arguments, 'status_after_send');
        if ($statusName !== null) {
            if (!in_array($statusName, self::STATUS_AFTER_SEND, true)) {
                throw new ToolError('Argument "status_after_send" must be one of: '.implode(', ', self::STATUS_AFTER_SEND).'.');
            }
            $choices['status'] = ConversationStatuses::BY_NAME[$statusName];
        }

        $assigneeId = $this->optionalInt($arguments, 'assignee_after_send');
        if ($assigneeId !== null) {
            $this->assertCanBeAssigned($mailbox, $assigneeId);
            $choices['assignee_id'] = $assigneeId;
        }

        if ($savedReply) {
            $choices['saved_reply_id'] = (int) $savedReply->id;
        }

        return $choices;
    }

    /**
     * Read and check the saved reply the caller wants to use for a draft.
     *
     * @return SavedReply|null Null when the argument is absent.
     *
     * @throws ToolError When the Saved Replies module is not active, the user cannot read the
     *                   saved reply, or the module would not offer it in the draft mailbox.
     */
    protected function savedReply(array $arguments, Mailbox $mailbox, User $user): ?SavedReply
    {
        $savedReplyId = $this->optionalInt($arguments, 'saved_reply_id');
        if ($savedReplyId === null) {
            return null;
        }
        if (!CompanionModules::isActive(CompanionModules::SAVED_REPLIES)) {
            throw new ToolError('saved_reply_id requires the Saved Replies module.');
        }

        $savedReply = SavedReplies::findReadable($savedReplyId, $user);
        if (!SavedReplies::isAvailableIn($savedReply, $mailbox)) {
            throw new ToolError('saved_reply_id is not available in this mailbox. Use a saved reply of the mailbox or a global one.');
        }

        return $savedReply;
    }

    /**
     * The HTML body of a new draft: the given body, which wins, else the text of the saved reply.
     *
     * @param Conversation|null $conversation The conversation the saved reply text is inserted into, for its variables.
     *
     * @throws ToolError When there is neither a body nor a saved reply.
     */
    protected function bodyHtml(array $arguments, ?SavedReply $savedReply, ?Conversation $conversation, User $user): string
    {
        if ($savedReply && $this->optionalString($arguments, 'body') === null) {
            return SavedReplies::insertableText($savedReply, $conversation, $user);
        }

        return BodyFormatter::toHtml($this->requireString($arguments, 'body'));
    }

    /**
     * Find a draft message the user may change.
     *
     * Missing and inaccessible drafts give the same answer, so ids cannot be probed.
     *
     * @throws ToolError When the thread is not an unsent draft of a conversation the user may update.
     */
    protected function findDraft(int $draftId, User $user): Thread
    {
        $draft = Thread::find($draftId);
        $conversation = $draft ? Conversation::find($draft->conversation_id) : null;
        if (!$draft || !$conversation || !$user->can('view', $conversation)) {
            throw new ToolError('Draft not found or not accessible.');
        }
        if (!$user->can('update', $conversation)) {
            throw new ToolError('You are not allowed to change this draft.');
        }
        if ((int) $draft->state !== Thread::STATE_DRAFT || (int) $draft->type !== Thread::TYPE_MESSAGE) {
            throw new ToolError('Thread '.$draft->id.' is not a draft; only unsent drafts can be changed.');
        }
        if ((int) $draft->subtype === Thread::SUBTYPE_FORWARD) {
            throw new ToolError('Forward drafts must be edited and sent from the FreeScout UI.');
        }

        return $draft;
    }

    /**
     * Save a draft through FreeScout's draft autosave and return the draft thread.
     *
     * @param User            $user            The author of the change.
     * @param array           $params          Request parameters of the core `save_draft` action.
     * @param SavedReply|null $attachmentsFrom A saved reply whose files are copied and attached to the draft, as the reply form does.
     *
     * @throws ToolError When core refuses the draft, or when the saved thread is somehow not a draft.
     */
    protected function saveDraft(User $user, array $params, SavedReply $attachmentsFrom = null): Thread
    {
        $copies = $attachmentsFrom ? SavedReplies::copyAttachments($attachmentsFrom) : [];
        if ($copies) {
            $ids = array_map(function ($copy) {
                return encrypt($copy->id);
            }, $copies);
            $params += ['attachments_all' => $ids, 'attachments' => $ids];
        }

        try {
            $result = CoreActions::conversationAjax($user, array_merge($params, ['action' => 'save_draft']));
        } catch (\Throwable $e) {
            SavedReplies::discardCopies($copies);

            throw $e;
        }

        $draft = Thread::find($result['thread_id'] ?? 0);
        if (!$draft) {
            throw new ToolError('FreeScout did not report the saved draft.');
        }
        if ((int) $draft->state !== Thread::STATE_DRAFT) {
            throw new ToolError('FreeScout did not keep the message as a draft.');
        }

        return $draft;
    }

    /**
     * Store the choices on the draft and build the tool result.
     */
    protected function result(Thread $draft, array $choices): array
    {
        if ($choices) {
            DraftChoices::store($draft, $choices);
        }

        return [
            'draft_id'        => (int) $draft->id,
            'conversation_id' => (int) $draft->conversation_id,
            'state'           => 'draft',
            'draft_choices'   => DraftChoices::forOutput($draft),
        ];
    }

    /**
     * @throws ToolError When the assignee is neither 0 nor an active user with access to the mailbox.
     */
    private function assertCanBeAssigned(Mailbox $mailbox, int $assigneeId): void
    {
        if ($assigneeId === 0) {
            return;
        }

        $assignee = $assigneeId > 0 ? User::find($assigneeId) : null;
        if (!$assignee || !$assignee->isActive() || !$mailbox->userHasAccess($assignee->id)) {
            throw new ToolError('Argument "assignee_after_send" must be 0 (unassign) or the id of an active user with access to the mailbox.');
        }
    }
}

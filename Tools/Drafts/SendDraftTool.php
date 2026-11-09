<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\McpForFreescout\Support\SendLater;
use Modules\McpForFreescout\Support\Signatures;

/**
 * The only tool that makes an email leave FreeScout.
 *
 * It posts to core's `send_reply` action what the web form posts when the user
 * presses Send, so the status, assignee, history entries, notifications and the
 * undo period behave exactly as for a person.
 */
class SendDraftTool extends DraftTool
{
    /**
     * Value of the "Assign to" field that means "Anyone", i.e. unassigned.
     */
    const UNASSIGNED = -1;

    /**
     * The statuses the reply form offers (spam is not among them).
     */
    const STATUSES_AFTER_SEND = [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING, Conversation::STATUS_CLOSED];

    public function name(): string
    {
        return 'send_draft';
    }

    public function description(): string
    {
        return 'SEND a draft: this delivers a real email to the recipients of the draft (and its CC and BCC), exactly like the Send button of FreeScout. '
            .'It cannot be recalled once the undo period of FreeScout has passed. '
            .'Send only a draft that was checked for the right recipients, sender and content; a draft can also be reviewed and sent by a person '
            .'from the FreeScout web interface instead, which is the safer choice when in doubt. '
            .'The draft comes from reply_to_conversation or create_conversation (email conversations only); the status and assignee chosen with status_after_send and assignee_after_send are applied. '
            .'Without status_after_send and assignee_after_send the mailbox settings for a reply apply (status after reply, who the conversation is assigned to), as when a person presses Send. '
            .'With send_at (Send Later module) the message is scheduled instead of sent now; given the id of a message that is already scheduled and no send_at, it is sent right away (this too needs the database queue driver).';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'draft_id' => ['type' => 'integer', 'description' => 'Draft id, as returned by reply_to_conversation or create_conversation.'],
                'send_at'  => ['type' => 'string', 'description' => 'Send later at this date and time instead of now: ISO-8601 with a UTC offset (for example 2030-01-31T09:30:00Z), between one minute and one year ahead. Requires the Send Later module, a queue worker and the database queue driver of FreeScout (refused with any other). The draft then becomes a scheduled message, which cancel_scheduled_send turns back into a draft.'],
            ],
            'required'             => ['draft_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $draftId = $this->requireInt($arguments, 'draft_id');
        $sendAt = $this->optionalString($arguments, 'send_at');

        $scheduled = $this->findScheduledMessage($draftId, $user);
        if ($scheduled) {
            if ($sendAt !== null) {
                throw new ToolError('This message is already scheduled. Cancel the scheduled send first, then update and reschedule.');
            }

            return $this->sendScheduledNow($scheduled, $user);
        }

        $draft = $this->findDraft($draftId, $user);
        $conversation = $draft->conversation;
        if ((int) $conversation->type !== Conversation::TYPE_EMAIL) {
            throw new ToolError('Only drafts of email conversations can be sent. Chat and phone conversations are answered from the FreeScout web interface.');
        }

        $scheduleDate = $sendAt !== null ? $this->scheduleDate($sendAt, $user) : null;
        // Sending publishes the conversation, which would silently restore a trashed one.
        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            throw new ToolError('The conversation is in the trash. Restore it with restore_conversation before sending.');
        }

        $this->assertSenderIsAllowed($draft, $conversation->mailbox);
        $params = $this->sendParams($draft, $conversation, $user, $this->checkedChoices($draft, $conversation->mailbox, $user), $scheduleDate);
        CoreActions::conversationAjax($user, array_merge($params, ['action' => 'send_reply']));

        $sent = Thread::find($draft->id);
        if ($scheduleDate === null) {
            // The send hooks of core run after the undo period, so the choices are also dropped here.
            DraftChoices::clear($sent);

            return $this->sentResult($sent);
        }

        // A scheduled message keeps its choices, so that cancelling the schedule leaves a complete draft;
        // the provider's send hooks drop them when the message really goes out.
        if (!SendLater::isScheduled($sent)) {
            $this->undoFailedSchedule($sent, $user);
        }

        return array_merge($this->sentResult($sent), [
            'state'        => 'scheduled',
            'scheduled_at' => Presenter::scheduledAt($sent),
        ]);
    }

    /**
     * Find a scheduled message of Send Later that the user may change.
     *
     * @return Thread|null Null when the thread is not a scheduled message or the user cannot reach it,
     *                     in which case findDraft() gives the usual answers.
     */
    private function findScheduledMessage(int $threadId, User $user): ?Thread
    {
        if (!CompanionModules::isActive(CompanionModules::SEND_LATER)) {
            return null;
        }

        $thread = Thread::find($threadId);
        $conversation = $thread ? Conversation::find($thread->conversation_id) : null;
        if (!$thread || !$conversation || !$user->can('view', $conversation) || !$user->can('update', $conversation)) {
            return null;
        }

        return SendLater::isScheduled($thread) ? $thread : null;
    }

    /**
     * Send a scheduled message now, with the module's own "Send now" action.
     *
     * @throws ToolError When the queue cannot be controlled, the conversation is in the trash or the message stays scheduled.
     */
    private function sendScheduledNow(Thread $thread, User $user): array
    {
        SendLater::assertQueueCanBeControlled();
        if ((int) Conversation::find($thread->conversation_id)->state === Conversation::STATE_DELETED) {
            throw new ToolError('The conversation is in the trash. Restore it with restore_conversation before sending.');
        }

        CoreActions::moduleAjax($user, SendLater::CONTROLLER, ['action' => 'send_now', 'thread_id' => $thread->id]);

        $sent = Thread::find($thread->id);
        if (SendLater::isScheduled($sent)) {
            throw new ToolError('The Send Later module did not release the scheduled message.');
        }

        return $this->sentResult($sent);
    }

    /**
     * Take back a message the Send Later module did not schedule.
     *
     * The module ignores a schedule it cannot read, which leaves the email queued for sending in
     * moments. Its own cancel action removes the queued jobs and returns the message to a draft.
     *
     * @throws ToolError Always; the message tells whether the email was stopped.
     */
    private function undoFailedSchedule(Thread $sent, User $user): void
    {
        try {
            CoreActions::moduleAjax($user, SendLater::CONTROLLER, ['action' => 'cancel', 'thread_id' => $sent->id]);
            $stopped = (int) Thread::find($sent->id)->state === Thread::STATE_DRAFT && !SendLater::hasQueuedJobs($sent->id);
        } catch (\Throwable $e) {
            $stopped = false;
        }

        if ($stopped) {
            throw new ToolError('Scheduling failed; nothing was sent and the message is a draft again.');
        }

        throw new ToolError('Scheduling failed and the email could NOT be stopped: it was queued and will be sent within moments. Do not send this message again; check the conversation in FreeScout.');
    }

    /**
     * The result fields of a message that has been handed over for sending.
     */
    private function sentResult(Thread $sent): array
    {
        return [
            'thread_id'       => (int) $sent->id,
            'conversation_id' => (int) $sent->conversation_id,
            'state'           => 'published',
            'status'          => Conversation::$statuses[Conversation::find($sent->conversation_id)->status] ?? 'unknown',
        ];
    }

    /**
     * The value of the `sl_schedule_date` field for the send_at argument.
     *
     * @throws ToolError When scheduling is not possible here or send_at is not a usable moment.
     */
    private function scheduleDate(string $sendAt, User $user): string
    {
        if (!CompanionModules::isActive(CompanionModules::SEND_LATER)) {
            throw new ToolError('Scheduling requires the Send Later module.');
        }
        SendLater::assertQueueCanBeControlled();

        return SendLater::scheduleField($sendAt, $user);
    }

    /**
     * The request parameters of core's `send_reply` action for a draft.
     *
     * They are the fields the reply form (or, for a new conversation, the create
     * form) posts when the user presses Send.
     *
     * @param User  $user    The user who sends.
     * @param array       $choices      The checked choices stored on the draft.
     * @param string|null $scheduleDate The `sl_schedule_date` value of the Send Later module, to schedule instead of sending now.
     */
    protected function sendParams(Thread $draft, Conversation $conversation, User $user, array $choices, string $scheduleDate = null): array
    {
        $mailbox = $conversation->mailbox;
        $isNewConversation = (int) $conversation->state === Conversation::STATE_DRAFT;

        $params = [
            'mailbox_id'      => $mailbox->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $draft->id,
            'body'            => (string) $draft->body,
            'from_alias'      => (string) $draft->from,
            'cc'              => $draft->getCcArray(),
            'bcc'             => $draft->getBccArray(),
            'status'          => $choices['status'] ?? $this->defaultStatus($conversation, $mailbox),
            'user_id'         => $this->assigneeParam($conversation, $user, $choices),
        ];

        if ($isNewConversation) {
            // The create form posts the recipients as a list and marks the draft as the new conversation.
            $params += [
                'is_create' => 1,
                'type'      => $conversation->type,
                'subject'   => $conversation->subject,
                'to'        => $draft->getToArray(),
            ];
        } else {
            // The reply form has a single To field.
            $to = $draft->getToArray();
            $params['to'] = $to ? reset($to) : '';
        }

        if (!empty($choices['saved_reply_id'])) {
            $params['saved_reply_id'] = $choices['saved_reply_id'];
        }

        // The Custom Signatures module reads the chosen signature from this field of the reply form.
        if (!empty($choices['signature_id'])) {
            $params['cs_signature'] = $choices['signature_id'];
        }

        // The Send Later module reads the scheduled date from this field of the send form.
        if ($scheduleDate !== null) {
            $params['sl_schedule_date'] = $scheduleDate;
        }

        return $params + $this->attachmentParams($draft);
    }

    /**
     * The status the reply form preselects. The form offers no spam option, so
     * a conversation in any other status than active, pending or closed falls back to active.
     */
    private function defaultStatus(Conversation $conversation, Mailbox $mailbox): int
    {
        $status = (int) $mailbox->ticket_status === Mailbox::TICKET_STATUS_KEEP_CURRENT
            ? (int) $conversation->status
            : (int) $mailbox->ticket_status;

        return in_array($status, self::STATUSES_AFTER_SEND, true) ? $status : Conversation::STATUS_ACTIVE;
    }

    /**
     * The "Assign to" value: the chosen assignee, else what the reply form preselects
     * for the mailbox's "assign on reply" setting.
     */
    private function assigneeParam(Conversation $conversation, User $user, array $choices): int
    {
        if (array_key_exists('assignee_id', $choices)) {
            return $choices['assignee_id'] > 0 ? $choices['assignee_id'] : self::UNASSIGNED;
        }

        $mailbox = $conversation->mailbox;
        $current = (int) $conversation->user_id;

        switch ((int) $mailbox->ticket_assignee) {
            case Mailbox::TICKET_ASSIGNEE_ANYONE:
                return self::UNASSIGNED;
            case Mailbox::TICKET_ASSIGNEE_REPLYING:
                return (int) $user->id;
            case Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED:
                $assignee = $current ?: (int) $user->id;
                break;
            default:
                $assignee = $current;
        }

        // The form lists the sender and the users that can be assigned; anyone else is not selectable.
        if ($assignee === (int) $user->id || $this->isAssignable($mailbox, $assignee)) {
            return $assignee;
        }

        return self::UNASSIGNED;
    }

    private function isAssignable(Mailbox $mailbox, int $userId): bool
    {
        return $userId > 0 && $mailbox->usersAssignable(false)->contains('id', $userId);
    }

    /**
     * Refuse a draft whose sender address the mailbox no longer allows.
     *
     * @throws ToolError When the address is neither the mailbox's own nor one of its reply aliases.
     */
    private function assertSenderIsAllowed(Thread $draft, Mailbox $mailbox): void
    {
        $from = mb_strtolower(trim((string) $draft->from), 'UTF-8');
        if ($from === '' || $from === mb_strtolower($mailbox->email, 'UTF-8')) {
            return;
        }

        $allowed = array_map(function ($alias) {
            return mb_strtolower($alias, 'UTF-8');
        }, array_keys($mailbox->getAliases(true, true)));
        if (!in_array($from, $allowed, true)) {
            throw new ToolError('The sender address of this draft ('.$draft->from.') is no longer an alias the mailbox may reply from. Choose another one with from_alias in update_draft.');
        }
    }

    /**
     * The attachment fields the form posts for the files already on the draft:
     * their encrypted ids, in `attachments_all` and `attachments`, and in `embeds` for inline images.
     */
    private function attachmentParams(Thread $draft): array
    {
        $all = [];
        $embeds = [];
        foreach ($draft->all_attachments()->get() as $attachment) {
            $id = encrypt($attachment->id);
            $all[] = $id;
            if ($attachment->embedded) {
                $embeds[] = $id;
            }
        }

        if (!$all) {
            return [];
        }

        return ['attachments_all' => $all, 'attachments' => $all] + ($embeds ? ['embeds' => $embeds] : []);
    }

    /**
     * The choices stored on the draft, checked again because the world may have changed since they were made.
     *
     * @throws ToolError When a choice no longer holds; nothing is sent.
     */
    private function checkedChoices(Thread $draft, Mailbox $mailbox, User $user): array
    {
        $choices = DraftChoices::get($draft);

        if (isset($choices['status']) && !in_array($choices['status'], self::STATUSES_AFTER_SEND, true)) {
            throw new ToolError('The status chosen for this draft is not allowed. Choose a new one with status_after_send in update_draft, or clear it with update_draft and status_after_send: null.');
        }

        if (!empty($choices['assignee_id'])) {
            $assignee = User::find($choices['assignee_id']);
            if (!$assignee || !$assignee->isActive() || !$mailbox->userHasAccess($assignee->id)) {
                throw new ToolError('The assignee chosen for this draft is no longer an active user with access to the mailbox. Choose a new one with assignee_after_send in update_draft, or clear it with update_draft and assignee_after_send: null.');
            }
        }

        if (!empty($choices['signature_id'])) {
            if (!CompanionModules::isActive(CompanionModules::CUSTOM_SIGNATURES)) {
                throw new ToolError('The signature chosen for this draft needs the Custom Mailbox Signatures module, which is not active. Clear the choice with update_draft and signature_id: null to send without it.');
            }
            if (!Signatures::belongsToMailbox($choices['signature_id'], $mailbox)) {
                throw new ToolError('The signature chosen for this draft no longer exists or no longer belongs to the mailbox. Choose a new one with signature_id in update_draft, or clear it with update_draft and signature_id: null.');
            }
        }

        if (!empty($choices['saved_reply_id'])) {
            if (!CompanionModules::isActive(CompanionModules::SAVED_REPLIES)) {
                throw new ToolError('The saved reply chosen for this draft needs the Saved Replies module, which is not active. Clear the choice with update_draft and saved_reply_id: null to send without it.');
            }
            $savedReply = SavedReplies::find($choices['saved_reply_id']);
            if (!$savedReply || !SavedReplies::isReadable($savedReply, $user) || !SavedReplies::isAvailableIn($savedReply, $mailbox)) {
                throw new ToolError('The saved reply chosen for this draft no longer exists or is no longer available to you in the mailbox. Choose another one with saved_reply_id in update_draft, or clear it with update_draft and saved_reply_id: null.');
            }
        }

        return $choices;
    }
}

<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\SavedReplies\Entities\SavedReply;

class CreateConversationTool extends DraftTool
{
    public function name(): string
    {
        return 'create_conversation';
    }

    public function description(): string
    {
        return 'Start a new email conversation as a DRAFT. Nothing is sent: the draft is only saved, '
            .'as the new conversation form does when you stop typing. Review it, change it with update_draft, and send it with send_draft. '
            .'Plain text is escaped and keeps its line breaks; a body that contains HTML is used as HTML. '
            .'Instead of a body, a saved_reply_id can supply the text; a body given with it wins and the saved reply then only adds its files. '
            .'Returns the draft id and the id of the new draft conversation.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => array_merge([
                'mailbox_id' => ['type' => 'integer', 'description' => 'Mailbox the conversation is created in, from list_mailboxes.'],
                'to'         => $this->recipientSchema('Recipients (email addresses), at least one.'),
                'subject'    => ['type' => 'string', 'maxLength' => self::MAX_SUBJECT_LENGTH, 'description' => 'Subject.'],
                'body'       => ['type' => 'string', 'description' => 'Message text. Required unless saved_reply_id is given.'],
                'from_alias' => ['type' => 'string', 'description' => 'Send from this alias of the mailbox (see from_aliases in get_mailbox) instead of the mailbox address.'],
                'cc'         => $this->recipientSchema('Copy recipients (email addresses).'),
                'bcc'        => $this->recipientSchema('Blind copy recipients (email addresses).'),
            ], $this->choiceSchemas()),
            'required'             => ['mailbox_id', 'to', 'subject'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailbox = Lookup::mailbox($this->requireInt($arguments, 'mailbox_id'), $user);
        $to = $this->recipients($arguments, 'to');
        if ($to === null) {
            throw new ToolError('Argument "to" is required.');
        }
        if (!$to) {
            throw new ToolError('Argument "to" must hold at least one email address.');
        }
        $subject = trim($this->requireString($arguments, 'subject'));
        if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
            throw new ToolError('Argument "subject" must be at most '.self::MAX_SUBJECT_LENGTH.' characters long.');
        }
        $from = $this->fromAlias($arguments, $mailbox, null);
        $savedReply = $this->savedReply($arguments, $mailbox, $user);
        $hasBody = $this->optionalString($arguments, 'body') !== null;
        if (!$hasBody && !$savedReply) {
            throw new ToolError('Argument "body" is required.');
        }
        $choices = $this->choices($arguments, $mailbox, $savedReply);

        $params = [
            'mailbox_id' => $mailbox->id,
            'is_create'  => 1,
            'type'       => Conversation::TYPE_EMAIL,
            // Core stores the status on the draft conversation and has no default for it.
            'status'     => Conversation::STATUS_ACTIVE,
            'from_alias' => $from,
            'to'         => $to,
            'cc'         => $this->recipients($arguments, 'cc') ?? [],
            'bcc'        => $this->recipients($arguments, 'bcc') ?? [],
            'subject'    => $subject,
        ];

        if ($hasBody || !$savedReply) {
            $draft = $this->saveDraft($user, $params + ['body' => $this->bodyHtml($arguments, $savedReply, null, $user)], $savedReply);
        } else {
            $draft = $this->saveWithSavedReplyText($user, $params, $savedReply);
        }

        return $this->result($draft, $choices);
    }

    /**
     * Save the draft of a new conversation whose body is the text of a saved reply.
     *
     * The reply form saves a new conversation before it inserts a saved reply, so that the
     * variables of the text can be replaced with data of the conversation and its customer.
     * This does the same: it saves the draft with an empty body, then saves it again with the text.
     */
    private function saveWithSavedReplyText(User $user, array $params, SavedReply $savedReply): Thread
    {
        $draft = $this->saveDraft($user, $params + ['body' => '']);

        try {
            $text = SavedReplies::insertableText($savedReply, Conversation::find($draft->conversation_id), $user);

            return $this->saveDraft($user, $params + [
                'conversation_id' => $draft->conversation_id,
                'thread_id'       => $draft->id,
                'body'            => $text,
            ], $savedReply);
        } catch (\Throwable $e) {
            // Do not leave the half-made draft behind.
            try {
                CoreActions::conversationAjax($user, ['action' => 'discard_draft', 'thread_id' => $draft->id]);
            } catch (\Throwable $discardFailure) {
                // The original failure is the one to report; the leftover draft is only logged.
                \Helper::logException($discardFailure, 'Could not discard the half-made draft '.$draft->id.' of create_conversation.');
            }

            throw $e;
        }
    }
}

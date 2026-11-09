<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\SavedReplies\Entities\SavedReply;

class UpdateDraftTool extends DraftTool
{
    /**
     * Arguments that change the draft; at least one must be given.
     */
    private const CHANGES = ['body', 'from_alias', 'to', 'cc', 'bcc', 'subject', 'signature_id', 'status_after_send', 'assignee_after_send', 'saved_reply_id'];

    /**
     * The arguments that remove their stored choice when given as null.
     */
    private const CLEARABLE = ['signature_id', 'status_after_send', 'assignee_after_send', 'saved_reply_id'];

    public function name(): string
    {
        return 'update_draft';
    }

    public function description(): string
    {
        return 'Change an unsent draft made with reply_to_conversation or create_conversation. Nothing is sent. '
            .'Give only what should change; everything else stays as it is. A recipient list replaces the current one. '
            .'The subject can only be changed on the draft of a new conversation. '
            .'A saved_reply_id replaces the body with the saved reply text unless a body is given, and adds the files of the saved reply to the draft. '
            .'Plain text is escaped and keeps its line breaks; a body that contains HTML is used as HTML. '
            .'An explicit null for signature_id, status_after_send, assignee_after_send or saved_reply_id clears that choice stored on the draft (it then falls back to the mailbox defaults); '
            .'this works even when the companion module is inactive, and clearing saved_reply_id leaves the body and files as they are.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => array_merge([
                'draft_id'   => ['type' => 'integer', 'description' => 'Draft id, as returned by reply_to_conversation or create_conversation.'],
                'body'       => ['type' => 'string', 'description' => 'New message text.'],
                'from_alias' => ['type' => 'string', 'description' => 'Send from this alias of the mailbox (see from_aliases in get_mailbox).'],
                'to'         => $this->recipientSchema('New recipients (email addresses). A reply has exactly one.'),
                'cc'         => $this->recipientSchema('New copy recipients; an empty list removes them.'),
                'bcc'        => $this->recipientSchema('New blind copy recipients; an empty list removes them.'),
                'subject'    => ['type' => 'string', 'maxLength' => self::MAX_SUBJECT_LENGTH, 'description' => 'New subject (drafts of new conversations only).'],
            ], $this->choiceSchemas(true)),
            'required'             => ['draft_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $draft = $this->findDraft($this->requireInt($arguments, 'draft_id'), $user);
        $conversation = $draft->conversation;
        $mailbox = $conversation->mailbox;
        $isNewConversation = (int) $conversation->state === Conversation::STATE_DRAFT;

        if (!$this->hasChange($arguments)) {
            throw new ToolError('Give at least one of: '.implode(', ', self::CHANGES).'.');
        }
        if (isset($arguments['subject']) && !$isNewConversation) {
            throw new ToolError('The subject can only be changed on the draft of a new conversation.');
        }

        $body = $this->optionalString($arguments, 'body');
        $subject = $this->optionalString($arguments, 'subject');
        if ($subject !== null) {
            $subject = trim($subject);
            if ($subject === '') {
                throw new ToolError('Argument "subject" must not be empty.');
            }
            if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                throw new ToolError('Argument "subject" must be at most '.self::MAX_SUBJECT_LENGTH.' characters long.');
            }
        }
        $to = $this->recipients($arguments, 'to');
        if ($to !== null && (!$to || (!$isNewConversation && count($to) !== 1))) {
            throw new ToolError($isNewConversation
                ? 'Argument "to" must hold at least one email address.'
                : 'Argument "to" must hold exactly one address; a reply has a single recipient. Put the others in "cc".');
        }
        $from = $this->fromAlias($arguments, $mailbox, $draft->from ?: null);
        $savedReply = $this->savedReply($arguments, $mailbox, $user);
        $choices = $this->choices($arguments, $mailbox, $savedReply, true);

        // Core saves the whole form again, so what is not changing is posted as it is.
        $to = $to ?? $draft->getToArray();
        $params = [
            'mailbox_id'      => $mailbox->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $draft->id,
            'body'            => $this->newBody($body, $savedReply, $conversation, $draft, $user),
            'from_alias'      => $from,
            'cc'              => $this->recipients($arguments, 'cc') ?? $draft->getCcArray(),
            'bcc'             => $this->recipients($arguments, 'bcc') ?? $draft->getBccArray(),
        ];
        if ($isNewConversation) {
            $params += [
                'is_create' => 1,
                'type'      => $conversation->type,
                'status'    => $conversation->status,
                'subject'   => $subject ?? $conversation->subject,
                'to'        => $to,
            ];
        } else {
            // A reply has a single To recipient, posted as a string like the reply form does.
            $params['to'] = $to ? reset($to) : '';
        }

        return $this->result($this->saveDraft($user, $params, $savedReply), $choices);
    }

    /**
     * The body to post: the given one, else the saved reply text, else the one the draft has.
     */
    private function newBody(?string $body, ?SavedReply $savedReply, Conversation $conversation, Thread $draft, User $user): string
    {
        if ($body !== null) {
            return BodyFormatter::toHtml($body);
        }

        return $savedReply ? SavedReplies::insertableText($savedReply, $conversation, $user) : (string) $draft->body;
    }

    private function hasChange(array $arguments): bool
    {
        foreach (self::CHANGES as $key) {
            if (isset($arguments[$key]) || (in_array($key, self::CLEARABLE, true) && $this->clearsChoice($arguments, $key))) {
                return true;
            }
        }

        return false;
    }
}

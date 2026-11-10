<?php

namespace Modules\McpForFreescout\Tools\Drafts;

use App\Conversation;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\FileInputs;
use Modules\McpForFreescout\Support\Lookup;

class ReplyToConversationTool extends DraftTool
{
    public function name(): string
    {
        return 'reply_to_conversation';
    }

    public function description(): string
    {
        return 'Write a reply to a conversation as a DRAFT. Nothing is sent to the customer: the draft is only saved, '
            .'as the reply form does when you stop typing. Review it, change it with update_draft, and send it with send_draft. '
            .'Without "to" the reply goes to the conversation\'s customer, and without "cc" it copies the conversation\'s CC recipients, like the reply form. '
            .'Plain text is escaped and keeps its line breaks; a body that contains HTML is used as HTML. '
            .'Instead of a body, a saved_reply_id can supply the text; a body given with it wins and the saved reply then only adds its files. '
            .'Files can be attached with "attachments"; they are saved with the draft and sent with it. '
            .'Returns the draft id.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => array_merge([
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'body'            => ['type' => 'string', 'description' => 'Reply text. Required unless saved_reply_id is given.'],
                'from_alias'      => ['type' => 'string', 'description' => 'Send from this alias of the mailbox (see from_aliases in get_mailbox) instead of the mailbox address.'],
                'to'              => $this->recipientSchema('The one recipient of the reply (default: the conversation\'s customer). Add further recipients with cc.'),
                'cc'              => $this->recipientSchema('Copy recipients (email addresses). Without it the conversation\'s CC recipients are kept, like the reply form does; an empty list means no CC.'),
                'bcc'             => $this->recipientSchema('Blind copy recipients (email addresses).'),
                'attachments'     => FileInputs::schema('Files to attach to the draft. Each one comes from a link, base64 content or an existing attachment.'),
            ], $this->choiceSchemas()),
            'required'             => ['conversation_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');

        $conversation = Lookup::conversation($conversationId, $user);
        if (!$user->can('update', $conversation)) {
            throw new ToolError('You are not allowed to reply to this conversation.');
        }
        // Saving a reply publishes the conversation, which would silently restore a trashed one.
        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            throw new ToolError('The conversation is in the trash. Restore it with restore_conversation before replying.');
        }
        if ((int) $conversation->state !== Conversation::STATE_PUBLISHED) {
            throw new ToolError('Replies cannot be added to a draft conversation. Change its draft with update_draft.');
        }
        if ((int) $conversation->type !== Conversation::TYPE_EMAIL) {
            throw new ToolError('Only email conversations can be replied to.');
        }

        $mailbox = $conversation->mailbox;
        $from = $this->fromAlias($arguments, $mailbox, null);
        $to = $this->replyRecipient($arguments, $conversation);
        $savedReply = $this->savedReply($arguments, $mailbox, $user);
        $body = $this->bodyHtml($arguments, $savedReply, $conversation, $user);
        $choices = $this->choices($arguments, $mailbox, $savedReply);
        // Everything that can be refused is read before any file is stored.
        $cc = $this->recipients($arguments, 'cc') ?? $this->defaultCc($conversation);
        $bcc = $this->recipients($arguments, 'bcc') ?? [];
        $files = $this->optionalFiles($arguments, $user);

        $draft = $this->saveDraft($user, [
            'mailbox_id'      => $mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => $body,
            'from_alias'      => $from,
            // A reply has a single To recipient, posted as a string like the reply form does.
            'to'              => $to,
            'cc'              => $cc,
            'bcc'             => $bcc,
        ], $savedReply, $files);

        return $this->result($draft, $choices);
    }

    /**
     * The CC recipients the reply form pre-fills: the conversation's CC list without
     * the mailbox and customer addresses, plus a CC'd person whose message is the
     * latest one waiting for an answer (the same rules as ConversationsController@view).
     *
     * @return string[]
     */
    private function defaultCc(Conversation $conversation): array
    {
        $cc = $conversation->getCcArray($conversation->getExcludeArray($conversation->mailbox));

        $threads = $conversation->threads()->orderBy('created_at', 'desc')->orderBy('id', 'desc')->get();
        foreach ($threads as $thread) {
            if ($thread->isUserMessage() && !$thread->isDraft()) {
                break;
            }
            if ($thread->isCustomerMessage()) {
                if ($thread->customer_id != $conversation->customer_id && $thread->from) {
                    $cc[] = $thread->from;
                }
                break;
            }
        }

        return array_values(array_unique($cc));
    }

    /**
     * The To address of the reply: the one given, or the one the reply form pre-selects.
     *
     * @throws ToolError When more than one address is given, or none can be found.
     */
    private function replyRecipient(array $arguments, Conversation $conversation): string
    {
        $given = $this->recipients($arguments, 'to');
        if ($given !== null) {
            if (count($given) !== 1) {
                throw new ToolError('Argument "to" must hold exactly one address; a reply has a single recipient. Put the others in "cc".');
            }

            return $given[0];
        }

        $default = $conversation->customer_email ?: ($conversation->customer ? $conversation->customer->getMainEmail() : '');
        if (!$default) {
            throw new ToolError('The conversation has no customer email address. Give "to".');
        }

        return $default;
    }
}

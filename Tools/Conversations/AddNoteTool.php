<?php

namespace Modules\McpForFreescout\Tools\Conversations;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\FileInputs;
use Modules\McpForFreescout\Support\Lookup;

class AddNoteTool extends Tool
{
    public function name(): string
    {
        return 'add_note';
    }

    public function description(): string
    {
        return 'Add an internal note to a conversation. The note is published immediately and only visible to users; '
            .'nothing is sent to the customer, and the status and assignee do not change. '
            .'Plain text is escaped and keeps its line breaks; a body that contains HTML is used as HTML. '
            .'Files can be attached with "attachments"; they are stored with the note.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'conversation_id' => ['type' => 'integer', 'description' => 'Conversation id, from list_conversations or search_conversations.'],
                'body'            => ['type' => 'string', 'description' => 'Note text.'],
                'attachments'     => FileInputs::schema('Files to attach to the note. Each one comes from a link, base64 content or an existing attachment.'),
            ],
            'required'             => ['conversation_id', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false];
    }

    public function handle(array $arguments, User $user): array
    {
        $conversationId = $this->requireInt($arguments, 'conversation_id');
        $body = $this->requireString($arguments, 'body');

        $conversation = Lookup::conversation($conversationId, $user);
        if (!$user->can('update', $conversation)) {
            throw new ToolError('You are not allowed to add notes to this conversation.');
        }

        // Core publishes the conversation when a reply is saved, which would silently restore a trashed one.
        if ((int) $conversation->state === Conversation::STATE_DELETED) {
            throw new ToolError('The conversation is in the trash. Restore it with restore_conversation before adding a note.');
        }
        if ((int) $conversation->state !== Conversation::STATE_PUBLISHED) {
            throw new ToolError('Notes cannot be added to a draft conversation.');
        }

        $previousNoteId = (int) $this->notesQuery($conversation->id, $user)->value('id');
        $files = $this->optionalFiles($arguments, $user);

        // Like the web form, post the current status and assignee, so both stay as they are.
        $params = [
            'action'          => 'send_reply',
            'is_note'         => 1,
            'mailbox_id'      => $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
            'body'            => BodyFormatter::toHtml($body),
            'status'          => $conversation->status,
            'user_id'         => $conversation->user_id ?: -1,
        ];
        if ($files) {
            // The form posts the encrypted ids of its uploads in both lists; core links them to the note.
            $ids = array_map(function (Attachment $file) {
                return encrypt($file->id);
            }, $files);
            $params += ['attachments_all' => $ids, 'attachments' => $ids];
        }

        try {
            CoreActions::conversationAjax($user, $params);
        } catch (\Throwable $e) {
            if ($files) {
                Attachment::deleteForever(collect($files));
            }

            throw $e;
        }

        // Core's answer does not name the thread it created, so take the caller's newest note.
        $note = $this->notesQuery($conversation->id, $user)->where('id', '>', $previousNoteId)->first();
        if (!$note) {
            throw new ToolError('FreeScout did not report the new note.');
        }

        return [
            'thread_id'       => (int) $note->id,
            'conversation_id' => (int) $conversation->id,
        ];
    }

    /**
     * The user's notes in a conversation, newest first.
     */
    private function notesQuery(int $conversationId, User $user)
    {
        return Thread::where('conversation_id', $conversationId)
            ->where('type', Thread::TYPE_NOTE)
            ->where('created_by_user_id', $user->id)
            ->orderBy('id', 'desc');
    }
}

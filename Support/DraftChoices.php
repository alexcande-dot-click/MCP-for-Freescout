<?php

namespace Modules\McpForFreescout\Support;

use App\Conversation;
use App\Thread;

/**
 * What the sender wants to happen when a draft is sent, kept in the draft
 * thread's meta data so it goes away together with the thread.
 *
 * The choices are `signature_id`, `status` (a Conversation::STATUS_* code),
 * `assignee_id` (0 means "unassign on send") and `saved_reply_id`.
 */
final class DraftChoices
{
    const META_KEY = 'mcpforfreescout.choices';

    const KEYS = ['signature_id', 'status', 'assignee_id', 'saved_reply_id'];

    /**
     * Merge choices into the ones already stored on a draft and save the thread.
     *
     * Only the keys present in $choices are touched: an integer sets the choice,
     * null removes it. Unknown keys are ignored.
     *
     * @param Thread $draft   The draft thread.
     * @param array  $choices Choice name => int|null.
     */
    public static function store(Thread $draft, array $choices): void
    {
        $stored = self::get($draft);

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $choices)) {
                continue;
            }
            if ($choices[$key] === null) {
                unset($stored[$key]);
            } else {
                $stored[$key] = (int) $choices[$key];
            }
        }

        if ($stored) {
            $draft->setMeta(self::META_KEY, $stored);
        } else {
            $draft->unsetMeta(self::META_KEY);
        }
        $draft->save();
    }

    /**
     * The choices stored on a draft; keys that were never set are absent.
     */
    public static function get(Thread $thread): array
    {
        $stored = $thread->getMeta(self::META_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $choices = [];
        foreach (self::KEYS as $key) {
            if (isset($stored[$key])) {
                $choices[$key] = (int) $stored[$key];
            }
        }

        return $choices;
    }

    /**
     * The choices of a draft as a tool result shows them: a JSON object, which stays `{}` when there are none.
     *
     * @return array|\stdClass
     */
    public static function forOutput(Thread $thread)
    {
        return self::get($thread) ?: new \stdClass();
    }

    /**
     * The choices of every draft the conversation page lets a viewer open for editing.
     *
     * FreeScout offers "Edit" on each message draft of a conversation to everyone who
     * can view it, so this covers all of them, not only the viewer's own. Drafts
     * without choices are left out.
     *
     * @return array[] Thread id => choices.
     */
    public static function forConversation(Conversation $conversation): array
    {
        $drafts = $conversation->threads()
            ->where('type', Thread::TYPE_MESSAGE)
            ->where('state', Thread::STATE_DRAFT)
            ->get();

        $byThread = [];
        foreach ($drafts as $draft) {
            $choices = self::get($draft);
            if ($choices) {
                $byThread[$draft->id] = $choices;
            }
        }

        return $byThread;
    }

    /**
     * Forget all choices of a thread and save it.
     */
    public static function clear(Thread $thread): void
    {
        $thread->unsetMeta(self::META_KEY);
        $thread->save();
    }
}

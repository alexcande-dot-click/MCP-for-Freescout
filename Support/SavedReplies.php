<?php

namespace Modules\McpForFreescout\Support;

use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Illuminate\Support\Collection;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\SavedReplies\Entities\SavedReply;

/**
 * Access to the saved replies of the Saved Replies module, following the rules
 * of the module's own reply form: a saved reply is offered in the mailbox it
 * belongs to and, when it is global, in every mailbox.
 *
 * Every method needs the module to be active; callers check
 * CompanionModules::isActive(CompanionModules::SAVED_REPLIES) first.
 */
final class SavedReplies
{
    /**
     * Find a saved reply by id.
     */
    public static function find(int $id): ?SavedReply
    {
        return SavedReply::find($id);
    }

    /**
     * Find a saved reply the user may read.
     *
     * Missing and unreadable saved replies give the same answer, so ids cannot be probed.
     *
     * @throws ToolError When the saved reply does not exist or is not readable by the user.
     */
    public static function findReadable(int $id, User $user): SavedReply
    {
        $reply = self::find($id);
        if (!$reply || !self::isReadable($reply, $user)) {
            throw new ToolError('Saved reply not found or not accessible.');
        }

        return $reply;
    }

    /**
     * Whether the user may read a saved reply: its mailbox is viewable by the
     * user, or the module offers it to every mailbox.
     */
    public static function isReadable(SavedReply $reply, User $user): bool
    {
        $mailbox = Mailbox::find($reply->mailbox_id);

        return ($mailbox && $user->can('view', $mailbox)) || self::isOfferedEverywhere($reply);
    }

    /**
     * Whether the module offers a saved reply in the reply form of a mailbox:
     * it belongs to that mailbox or is offered everywhere.
     */
    public static function isAvailableIn(SavedReply $reply, Mailbox $mailbox): bool
    {
        return (int) $reply->mailbox_id === (int) $mailbox->id || self::isOfferedEverywhere($reply);
    }

    /**
     * Whether a saved reply is global itself or sits below a global one.
     */
    public static function isOfferedEverywhere(SavedReply $reply): bool
    {
        if ($reply->global) {
            return true;
        }

        $seen = [(int) $reply->id => true];
        $parentId = (int) $reply->parent_saved_reply_id;
        while ($parentId > 0 && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $parent = SavedReply::find($parentId);
            if (!$parent) {
                return false;
            }
            if ($parent->global) {
                return true;
            }
            $parentId = (int) $parent->parent_saved_reply_id;
        }

        return false;
    }

    /**
     * The saved replies the reply form of a mailbox offers, ordered like the form orders them.
     *
     * These are the replies of the mailbox plus the global ones of other mailboxes. A
     * global reply whose parent is not offered is moved to the top level, as the form does.
     * The returned models are only for presentation; their parent ids may differ from the stored ones.
     */
    public static function offeredIn(Mailbox $mailbox): Collection
    {
        $replies = SavedReply::where('mailbox_id', $mailbox->id)->get();

        $mailboxIds = SavedReply::where('global', true)
            ->where('mailbox_id', '!=', $mailbox->id)
            ->pluck('mailbox_id')
            ->unique()
            ->values()
            ->all();
        if ($mailboxIds) {
            $candidates = SavedReply::whereIn('mailbox_id', $mailboxIds)->get()->keyBy('id');
            $offered = $candidates->filter(function (SavedReply $reply) use ($candidates) {
                return self::isOfferedWithin($reply, $candidates);
            });
            foreach ($offered as $reply) {
                if (!$offered->has((int) $reply->parent_saved_reply_id)) {
                    $reply->parent_saved_reply_id = null;
                }
            }
            $replies = $replies->merge($offered->values());
        }

        return $replies->sortBy(function (SavedReply $reply) {
            return [(int) $reply->sort_order, (int) $reply->id];
        })->values();
    }

    /**
     * The saved reply text as it should be inserted into a message: with the variables
     * (conversation, mailbox, customer and user) replaced by core, and dangerous tags removed.
     *
     * @param Conversation|null $conversation The conversation the text is inserted into, if any.
     */
    public static function insertableText(SavedReply $reply, ?Conversation $conversation, User $user): string
    {
        $text = (string) $reply->text;
        if ($conversation) {
            $text = self::withCustomer($conversation)->replaceTextVars($text, ['user' => $user]);
        }

        return \Helper::stripDangerousTags($text);
    }

    /**
     * Copy the attachments of a saved reply, so the draft gets files of its own.
     *
     * The copies belong to no thread yet; posting them with a draft attaches them to it.
     * It is all or nothing: when one file cannot be copied, the copies made so far are removed again.
     *
     * @return Attachment[]
     *
     * @throws ToolError When a file of the saved reply is missing or cannot be copied.
     */
    public static function copyAttachments(SavedReply $reply): array
    {
        $copies = [];
        try {
            foreach ($reply->getAttachments(false) as $attachment) {
                $copy = $attachment->duplicate();
                if ($copy && !$copy->file_dir) {
                    // Core keeps the database row when the file itself could not be written.
                    Attachment::where('id', $copy->id)->delete();
                    $copy = null;
                }
                if (!$copy) {
                    throw new ToolError('The file "'.$attachment->file_name.'" of the saved reply could not be copied. Check the saved reply in FreeScout.');
                }
                $copies[] = $copy;
            }
        } catch (\Throwable $e) {
            self::discardCopies($copies);

            throw $e;
        }

        return $copies;
    }

    /**
     * Delete copies made with copyAttachments() that were not attached to a draft.
     *
     * @param Attachment[] $copies
     */
    public static function discardCopies(array $copies): void
    {
        if ($copies) {
            Attachment::deleteForever(collect($copies));
        }
    }

    /**
     * The conversation to replace the variables of a text in.
     *
     * Core takes the customer from the conversation. When the conversation has none, a copy
     * of it is pointed to the customer found by the conversation's email address or by the
     * recipient of its first thread; the stored conversation is not changed.
     */
    private static function withCustomer(Conversation $conversation): Conversation
    {
        if ($conversation->customer) {
            return $conversation;
        }

        $email = $conversation->customer_email;
        if (!$email) {
            $first = $conversation->getFirstThread();
            $email = $first ? $first->getToFirst() : null;
        }
        $customer = $email ? Customer::getByEmail($email) : null;
        if (!$customer) {
            return $conversation;
        }

        $copy = clone $conversation;
        $copy->customer_id = $customer->id;

        return $copy;
    }

    /**
     * Whether a saved reply is global or sits below a global one, within an already loaded set.
     */
    private static function isOfferedWithin(SavedReply $reply, Collection $all): bool
    {
        $seen = [];
        $current = $reply;
        while ($current && !isset($seen[(int) $current->id])) {
            if ($current->global) {
                return true;
            }
            $seen[(int) $current->id] = true;
            $current = $all->get((int) $current->parent_saved_reply_id);
        }

        return false;
    }
}

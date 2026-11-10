<?php

namespace Modules\McpForFreescout\Support;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Mailbox;
use App\SendLog;
use App\Thread;
use App\User;

/**
 * Turns FreeScout records into the plain arrays the tools return.
 *
 * Dates are ISO-8601 UTC strings (null when absent). Every string is valid
 * UTF-8, because email content may contain broken byte sequences that would
 * otherwise make the JSON encoding of a tool result fail.
 */
final class Presenter
{
    private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const CONVERSATION_STATES = [
        Conversation::STATE_DRAFT     => 'draft',
        Conversation::STATE_PUBLISHED => 'published',
        Conversation::STATE_DELETED   => 'deleted',
    ];

    private const THREAD_STATES = [
        Thread::STATE_DRAFT     => 'draft',
        Thread::STATE_PUBLISHED => 'published',
        Thread::STATE_HIDDEN    => 'hidden',
        Thread::STATE_REVIEW    => 'review',
    ];

    /**
     * A mailbox as seen by the given user.
     */
    public static function mailbox(Mailbox $mailbox, User $user): array
    {
        $presented = [
            'id'           => (int) $mailbox->id,
            'name'         => self::text($mailbox->name),
            'email'        => self::text($mailbox->email),
            'from_aliases' => self::textList(array_keys($mailbox->getAliases(true, true))),
            'signature'    => self::text($mailbox->signature),
        ];

        if (CompanionModules::isActive(CompanionModules::CUSTOM_SIGNATURES)) {
            $presented['custom_signatures'] = \DB::table('custom_signatures')
                ->where('mailbox_id', $mailbox->id)
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(function ($signature) {
                    return ['id' => (int) $signature->id, 'name' => self::text($signature->name)];
                })
                ->all();
        }

        $presented['permissions'] = [
            'update_settings'  => $user->can('updateSettings', $mailbox),
            'update_signature' => $user->can('updateEmailSignature', $mailbox),
        ];

        return $presented;
    }

    /**
     * A custom signature of the Custom Mailbox Signatures module.
     *
     * @param \Modules\CustomSignatures\Entities\CustomSignature $signature
     */
    public static function signature($signature): array
    {
        return [
            'id'         => (int) $signature->id,
            'mailbox_id' => (int) $signature->mailbox_id,
            'name'       => self::text($signature->name),
            'text'       => self::text($signature->text),
        ];
    }

    /**
     * A saved reply of the Saved Replies module, with its text and attachments.
     *
     * @param \Modules\SavedReplies\Entities\SavedReply $reply
     */
    public static function savedReply($reply): array
    {
        return [
            'id'          => (int) $reply->id,
            'name'        => self::text($reply->name),
            'text'        => self::text($reply->text),
            'mailbox_id'  => (int) $reply->mailbox_id,
            'parent_id'   => $reply->parent_saved_reply_id ? (int) $reply->parent_saved_reply_id : null,
            'global'      => (bool) $reply->global,
            'attachments' => $reply->getAttachments()->map(function ($attachment) {
                return [
                    'id'        => (int) $attachment->id,
                    'file_name' => self::text($attachment->file_name),
                    'mime_type' => self::text($attachment->mime_type),
                    'size'      => $attachment->size !== null ? (int) $attachment->size : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * A tag of the Tags module, with its colour as a name.
     *
     * @param \Modules\Tags\Entities\Tag $tag
     */
    public static function tag($tag): array
    {
        return [
            'id'      => (int) $tag->id,
            'name'    => self::text($tag->name),
            'color'   => Tags::colorName($tag),
            'counter' => (int) $tag->counter,
        ];
    }

    /**
     * A tree of saved replies, as built by SavedReply::listToTree(), without their texts.
     *
     * @param \Modules\SavedReplies\Entities\SavedReply[] $nodes
     */
    public static function savedReplyTree(array $nodes): array
    {
        return array_map(function ($reply) {
            return [
                'id'              => (int) $reply->id,
                'name'            => self::text($reply->name),
                'parent_id'       => $reply->parent_saved_reply_id ? (int) $reply->parent_saved_reply_id : null,
                'global'          => (bool) $reply->global,
                'has_attachments' => !empty($reply->attachments),
                'children'        => self::savedReplyTree($reply->saved_replies),
            ];
        }, $nodes);
    }

    /**
     * A FreeScout user.
     */
    public static function user(User $user): array
    {
        return [
            'id'         => (int) $user->id,
            'first_name' => self::text($user->first_name),
            'last_name'  => self::text($user->last_name),
            'email'      => self::text($user->email),
            'role'       => $user->getRoleName(),
        ];
    }

    /**
     * A customer with all email addresses and phone numbers.
     */
    public static function customer(Customer $customer): array
    {
        $emails = $customer->emails->sortBy('id')->pluck('email')->values()->all();

        $phones = [];
        foreach ($customer->getPhones() as $phone) {
            if (!empty($phone['value'])) {
                $phones[] = self::text($phone['value']);
            }
        }

        return [
            'id'      => (int) $customer->id,
            'name'    => self::text($customer->getFullName(true)),
            'emails'  => self::textList($emails),
            'phones'  => $phones,
            'company' => self::text($customer->company),
        ];
    }

    /**
     * A conversation without its threads.
     *
     * `starred` tells whether the given user has starred it. Callers that present
     * many conversations pass `$starredIds` (see starredAmong) to avoid one query each.
     * `tags` is present only when the Tags module is active, `scheduled` only
     * when the Send Later module is active.
     *
     * @param int[]|null $starredIds
     */
    public static function conversationSummary(Conversation $conversation, User $user, ?array $starredIds = null): array
    {
        $customer = $conversation->customer;

        $presented = [
            'id'          => (int) $conversation->id,
            'number'      => (int) $conversation->number,
            'mailbox_id'  => (int) $conversation->mailbox_id,
            'subject'     => self::text($conversation->subject),
            'status'      => Conversation::$statuses[$conversation->status] ?? 'unknown',
            'state'       => self::CONVERSATION_STATES[$conversation->state] ?? 'unknown',
            'assignee_id' => $conversation->user_id ? (int) $conversation->user_id : null,
            'customer'    => $customer ? [
                'id'    => (int) $customer->id,
                'name'  => self::text($customer->getFullName(true)),
                'email' => self::text($conversation->customer_email ?: $customer->getMainEmail()),
            ] : null,
            'preview'       => self::text($conversation->preview),
            'last_reply_at' => self::date($conversation->last_reply_at),
            'starred'       => in_array((int) $conversation->id, $starredIds ?? self::starredAmong([$conversation->id], $user), true),
        ];

        if (CompanionModules::isActive(CompanionModules::TAGS)) {
            $presented['tags'] = self::textList(\DB::table('conversation_tag')
                ->join('tags', 'tags.id', '=', 'conversation_tag.tag_id')
                ->where('conversation_tag.conversation_id', $conversation->id)
                ->orderBy('tags.name')
                ->pluck('tags.name')
                ->all());
        }

        if (CompanionModules::isActive(CompanionModules::SEND_LATER)) {
            $presented['scheduled'] = (bool) $conversation->scheduled;
        }

        return $presented;
    }

    /**
     * The ids, among the given conversations, that the user has starred.
     *
     * Read from the database on every call: Conversation::isStarredByUser() keeps a
     * per-mailbox cache that goes stale as soon as a conversation is starred or unstarred.
     *
     * @param  int[] $conversationIds
     * @return int[]
     */
    public static function starredAmong(array $conversationIds, User $user): array
    {
        if (!$conversationIds) {
            return [];
        }

        return \DB::table('conversation_folder')
            ->join('folders', 'folders.id', '=', 'conversation_folder.folder_id')
            ->where('folders.type', Folder::TYPE_STARRED)
            ->where('folders.user_id', $user->id)
            ->whereIn('conversation_folder.conversation_id', $conversationIds)
            ->pluck('conversation_folder.conversation_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * A thread. HTML bodies are given as plain text.
     *
     * `created_by` holds a user id or a customer id, as told by `created_by_type`.
     * `delivery` and `opened_at` are present on messages only. `opened_at` is set by a
     * tracking image, so it is not proof that the customer read the message.
     * `draft_choices` (what happens on send, see DraftChoices) is present on drafts only.
     * `scheduled_at` is present only when the Send Later module is active, and is null
     * unless the thread is a message waiting for its scheduled time.
     */
    public static function thread(Thread $thread): array
    {
        if ($thread->created_by_user_id) {
            $createdBy = (int) $thread->created_by_user_id;
            $createdByType = 'user';
        } elseif ($thread->created_by_customer_id) {
            $createdBy = (int) $thread->created_by_customer_id;
            $createdByType = 'customer';
        } else {
            $createdBy = null;
            $createdByType = null;
        }

        $presented = [
            'id'              => (int) $thread->id,
            'type'            => Thread::$types[$thread->type] ?? 'other',
            'state'           => self::THREAD_STATES[$thread->state] ?? 'unknown',
            'from'            => self::text($thread->from),
            'to'              => self::textList($thread->getToArray()),
            'cc'              => self::textList($thread->getCcArray()),
            'bcc'             => self::textList($thread->getBccArray()),
            'body_text'       => self::text(\Helper::htmlToText(self::text($thread->body))),
            'created_by'      => $createdBy,
            'created_by_type' => $createdByType,
            'created_at'      => self::date($thread->created_at),
            'attachments'     => Attachments::ofThread($thread),
        ];

        // A draft was never handed to the mailer; a scheduled message is published and still queued.
        if ((int) $thread->type === Thread::TYPE_MESSAGE && (int) $thread->state === Thread::STATE_PUBLISHED) {
            $presented['delivery'] = self::delivery($thread);
            $presented['opened_at'] = self::date($thread->opened_at);
        }

        if ((int) $thread->state === Thread::STATE_DRAFT) {
            $presented['draft_choices'] = DraftChoices::forOutput($thread);
        }

        if (CompanionModules::isActive(CompanionModules::SEND_LATER)) {
            $presented['scheduled_at'] = self::scheduledAt($thread);
        }

        return $presented;
    }

    /**
     * Where a sent message stands: queued, sent, delivered, send_error or delivery_error.
     */
    private static function delivery(Thread $thread): array
    {
        switch ((int) $thread->send_status) {
            case 0:
                $status = 'queued';
                break;
            case SendLog::STATUS_ACCEPTED:
                $status = 'sent';
                break;
            case SendLog::STATUS_DELIVERY_SUCCESS:
            case SendLog::STATUS_OPENED:
            case SendLog::STATUS_CLICKED:
            case SendLog::STATUS_UNSUBSCRIBED:
            case SendLog::STATUS_COMPLAINED:
                $status = 'delivered';
                break;
            case SendLog::STATUS_SEND_ERROR:
            case SendLog::STATUS_SEND_INTERMEDIATE_ERROR:
                $status = 'send_error';
                break;
            default:
                $status = 'delivery_error';
        }

        $data = $thread->getSendStatusData();

        return [
            'status' => $status,
            'error'  => self::text($data['msg'] ?? null),
        ];
    }

    /**
     * The moment a scheduled message of the Send Later module will be sent, or null when it is not scheduled.
     */
    public static function scheduledAt(Thread $thread): ?string
    {
        return SendLater::isScheduled($thread) ? self::date(SendLater::scheduledAt($thread)) : null;
    }

    /**
     * Normalise a value to valid UTF-8; invalid byte sequences are replaced.
     *
     * @param mixed $value
     */
    private static function text($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
    }

    private static function textList($values): array
    {
        return array_map([self::class, 'text'], array_values((array) $values));
    }

    private static function date($date): ?string
    {
        if (!$date) {
            return null;
        }

        return $date->copy()->setTimezone('UTC')->format(self::DATE_FORMAT);
    }
}

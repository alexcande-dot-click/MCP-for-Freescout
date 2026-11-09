<?php

namespace Modules\McpForFreescout\Support;

use App\Thread;
use App\User;
use Carbon\Carbon;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Access to what the Send Later module stores: a scheduled message is a published
 * message thread carrying the `sl.schedule` meta, a date and time in the application's timezone.
 *
 * Callers check CompanionModules::isActive(CompanionModules::SEND_LATER) first.
 */
final class SendLater
{
    /**
     * Name of the thread meta that holds the scheduled date.
     */
    const META_NAME = 'sl.schedule';

    /**
     * The format the module reads from the `sl_schedule_date` field (seconds are always zero).
     */
    const FIELD_FORMAT = 'Y-m-d H:i:00';

    /**
     * Fewest seconds between now and a scheduled send.
     */
    const MIN_LEAD_SECONDS = 60;

    /**
     * The module's AJAX controller, which performs `send_now` and `cancel`.
     */
    const CONTROLLER = 'Modules\\SendLater\\Http\\Controllers\\SendLaterController';

    /**
     * Whether the thread is a message waiting to be sent at its scheduled time.
     */
    public static function isScheduled(Thread $thread): bool
    {
        return (int) $thread->state === Thread::STATE_PUBLISHED
            && (int) $thread->type === Thread::TYPE_MESSAGE
            && self::scheduledAt($thread) !== null;
    }

    /**
     * The moment the thread is scheduled for, in UTC; null when it has no (readable) schedule.
     */
    public static function scheduledAt(Thread $thread): ?Carbon
    {
        $stored = $thread->getMeta(self::META_NAME);
        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $stored, config('app.timezone'))->setTimezone('UTC');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Whether the default queue connection is backed by the database, the only driver whose
     * jobs table the Send Later module can list, cancel and release.
     */
    public static function queueCanBeControlled(): bool
    {
        return config('queue.connections.'.config('queue.default').'.driver') === 'database';
    }

    /**
     * Refuse to schedule, cancel or release a message unless the queue is the database queue.
     *
     * With another driver the delayed email lives outside the table the module controls, so a
     * cancelled message would still be delivered.
     *
     * @throws ToolError When the default queue connection does not use the database driver.
     */
    public static function assertQueueCanBeControlled(): void
    {
        if (!self::queueCanBeControlled()) {
            $driver = config('queue.connections.'.config('queue.default').'.driver');

            throw new ToolError('Send Later control through MCP requires the database queue driver of FreeScout (the current queue driver is "'.(is_string($driver) ? $driver : 'unknown').'"); nothing was changed.');
        }
    }

    /**
     * Whether the module still has queued jobs for the message.
     */
    public static function hasQueuedJobs(int $threadId): bool
    {
        return count(\SendLater::getJobs($threadId)) > 0;
    }

    /**
     * Read the `send_at` argument as the value of the module's `sl_schedule_date` field.
     *
     * The module reads that field in the user's timezone and to the minute. The moment is
     * therefore rounded up to the next whole minute (never earlier than asked), expressed in
     * the user's timezone, and checked to read back as exactly that instant: a wall-clock time
     * that occurs twice or not at all in the user's or the application's timezone is refused.
     *
     * @param string $sendAt ISO-8601 date and time with an offset (or Z).
     *
     * @return string The field value.
     *
     * @throws ToolError When the value is not a real date and time, is less than a minute or
     *                   more than a year ahead, or falls in a daylight-saving change.
     */
    public static function scheduleField(string $sendAt, User $user): string
    {
        $moment = self::parseMoment(trim($sendAt));

        $now = Carbon::now();
        if ($moment->lt($now->copy()->addSeconds(self::MIN_LEAD_SECONDS))) {
            throw new ToolError('send_at must be at least one minute in the future.');
        }

        $minute = $moment->copy()->setTime($moment->hour, $moment->minute, 0);
        if ($minute->lt($moment)) {
            $minute->addMinute();
        }
        if ($minute->gt($now->copy()->addYear())) {
            throw new ToolError('send_at must be at most one year in the future.');
        }

        $timezone = $user->timezone ?: config('app.timezone');
        $field = $minute->copy()->setTimezone($timezone)->format(self::FIELD_FORMAT);
        if (!self::readsBackAs($field, $timezone, $minute)) {
            throw new ToolError('The requested time falls in a daylight-saving change in your timezone; choose another time.');
        }

        return $field;
    }

    /**
     * Parse an ISO-8601 date and time with an offset, refusing values that do not exist
     * (such as February 30th or hour 24) instead of letting them roll over.
     *
     * @return Carbon The moment, in UTC.
     *
     * @throws ToolError
     */
    private static function parseMoment(string $sendAt): Carbon
    {
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-](\d{2})(?::?(\d{2}))?)$/i';
        $valid = preg_match($pattern, $sendAt, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23
            && (int) $m[5] <= 59
            && (int) ($m[6] ?? 0) <= 59
            && (int) ($m[8] ?? 0) <= 23
            && (int) ($m[9] ?? 0) <= 59;

        if ($valid) {
            try {
                return Carbon::parse($sendAt)->setTimezone('UTC');
            } catch (\Exception $e) {
                // Reported below.
            }
        }

        throw new ToolError('send_at must be an existing ISO-8601 date and time with a UTC offset, such as 2030-01-31T09:30:00Z or 2030-01-31T10:30:00+01:00.');
    }

    /**
     * Whether the module, given the field, ends up with the intended instant: it reads the field in
     * the user's timezone, stores it in the application's timezone and parses the stored value back.
     */
    private static function readsBackAs(string $field, string $timezone, Carbon $intended): bool
    {
        try {
            $stored = Carbon::createFromFormat(self::FIELD_FORMAT, $field, $timezone)
                ->setTimezone(config('app.timezone'))
                ->format(self::FIELD_FORMAT);

            return Carbon::parse($stored, config('app.timezone'))->getTimestamp() === $intended->getTimestamp();
        } catch (\Exception $e) {
            return false;
        }
    }
}

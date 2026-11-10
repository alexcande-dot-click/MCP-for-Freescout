<?php

namespace Modules\McpForFreescout\Tests\Integration\Companions;

use App\Conversation;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Mcp\ToolRegistry;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\CoreActions;
use Modules\McpForFreescout\Support\DraftChoices;
use Modules\McpForFreescout\Support\Presenter;
use Modules\McpForFreescout\Support\SendLater;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;
use Modules\McpForFreescout\Tools\Drafts\CreateConversationTool;
use Modules\McpForFreescout\Tools\Drafts\ReplyToConversationTool;
use Modules\McpForFreescout\Tools\Drafts\SendDraftTool;
use Modules\McpForFreescout\Tools\Drafts\UpdateDraftTool;
use Modules\McpForFreescout\Tools\SendLater\CancelScheduledSendTool;
use Modules\CustomSignatures\Entities\CustomSignature;

class SendLaterTest extends TestCase
{
    private $user;
    private $colleague;
    private $outsider;
    private $mailbox;
    private $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireModule(CompanionModules::SEND_LATER);

        // A user timezone that differs from both UTC and the application timezone.
        $this->user = Fixtures::user(['timezone' => 'America/New_York']);
        $this->colleague = Fixtures::user();
        $this->outsider = Fixtures::user();
        $this->mailbox = Fixtures::mailbox([], [$this->user, $this->colleague]);
        $this->conversation = Fixtures::conversation($this->mailbox, Fixtures::customer('customer@example.test'));
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(ToolRegistry::class);

        parent::tearDown();
    }

    public function test_send_at_schedules_and_marks_conversation_scheduled()
    {
        $draft = $this->draftReply();
        $jobs = $this->customerEmailJobs();
        $sendAt = Carbon::now('UTC')->addDays(3)->setTime(14, 30, 0);

        $result = (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $sendAt->format('Y-m-d\TH:i:s\Z')], $this->user);

        $this->assertSame('scheduled', $result['state']);
        $this->assertSame($sendAt->format('Y-m-d\TH:i:s\Z'), $result['scheduled_at']);
        $this->assertSame((int) $draft->id, $result['thread_id']);

        $thread = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertNotEmpty($thread->getMeta('sl.schedule'));
        $this->assertSame(1, (int) Conversation::find($this->conversation->id)->scheduled);

        // The email is queued, but only to run at the scheduled time.
        $this->assertSame($jobs + 1, $this->customerEmailJobs());
        $job = \DB::table('jobs')->where('payload', 'like', '%SendReplyToCustomer%')->orderBy('id', 'desc')->first();
        $this->assertGreaterThan(time() + 3 * 86400 - 3600, (int) $job->available_at);
    }

    public function test_scheduled_time_is_read_in_the_users_timezone()
    {
        $minute = Carbon::now('UTC')->addDays(5)->setTime(9, 15, 0);
        $sendAt = $minute->copy()->addSeconds(40);

        $result = (new SendDraftTool())->handle(
            ['draft_id' => $this->draftReply()->id, 'send_at' => $sendAt->copy()->setTimezone('Asia/Kolkata')->toIso8601String()],
            $this->user
        );

        // The module stores the date in the application timezone, to the minute; a part-minute rounds up.
        $minute->addMinute();
        $expected = $minute->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:00');
        $this->assertNotSame('UTC', config('app.timezone'), 'The test needs a non-UTC application timezone to mean something.');
        $this->assertSame($expected, Thread::find($result['thread_id'])->getMeta('sl.schedule'));
        $this->assertSame($minute->format('Y-m-d\TH:i:s\Z'), $result['scheduled_at']);
    }

    public function test_the_schedule_field_is_the_moment_in_the_users_timezone()
    {
        $moment = Carbon::now('UTC')->addDays(10)->setTime(12, 0, 0);
        $isoUtc = $moment->format('Y-m-d\TH:i:s\Z');

        $this->assertSame($moment->copy()->setTimezone('America/New_York')->format('Y-m-d H:i:00'), SendLater::scheduleField($isoUtc, $this->user));
        $this->assertSame(
            $moment->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:00'),
            SendLater::scheduleField($isoUtc, Fixtures::user(['timezone' => 'Asia/Kolkata']))
        );
        $this->assertSame(
            $moment->format('Y-m-d H:i:00'),
            SendLater::scheduleField($moment->copy()->setTimezone('+02:00')->toIso8601String(), Fixtures::user(['timezone' => 'UTC']))
        );
    }

    public function test_the_scheduled_message_is_presented_with_its_time()
    {
        $draft = $this->draftReply();
        $sendAt = Carbon::now('UTC')->addDays(2)->setTime(8, 0, 0)->format('Y-m-d\TH:i:s\Z');

        $this->assertNull(Presenter::thread($draft)['scheduled_at']);

        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $sendAt], $this->user);

        $thread = Presenter::thread(Thread::find($draft->id));
        $this->assertSame('published', $thread['state']);
        $this->assertSame($sendAt, $thread['scheduled_at']);
        $this->assertTrue(Presenter::conversationSummary(Conversation::find($this->conversation->id), $this->user)['scheduled']);

        // An ordinary message is not scheduled.
        $this->assertNull(Presenter::thread(Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first())['scheduled_at']);
    }

    public function test_threads_have_no_scheduled_at_when_the_module_is_inactive()
    {
        $this->withCompanions(false, function () {
            $this->assertArrayNotHasKey('scheduled_at', Presenter::thread($this->draftReply()));
        });
    }

    /**
     * @dataProvider unusableMoments
     */
    public function test_an_unusable_send_at_is_refused_and_nothing_is_sent(callable $sendAt, string $message)
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();

        $this->assertRefused(function () use ($draft, $sendAt) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $sendAt()], $this->user);
        }, $message);

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
        $this->assertSame(0, (int) Conversation::find($this->conversation->id)->scheduled);
    }

    public function unusableMoments(): array
    {
        $at = function (string $modifier) {
            return function () use ($modifier) {
                return Carbon::now('UTC')->modify($modifier)->format('Y-m-d\TH:i:s\Z');
            };
        };

        return [
            'in the past'        => [$at('-1 hour'), 'at least one minute in the future'],
            'now'                => [$at('+0 seconds'), 'at least one minute in the future'],
            'half a minute away' => [$at('+30 seconds'), 'at least one minute in the future'],
            'too far ahead'      => [$at('+2 years'), 'at most one year'],
            'just over a year'   => [$at('+1 year +1 day'), 'at most one year'],
            'unparsable text'    => [function () {
                return 'next tuesday';
            }, 'ISO-8601'],
            'a date only'        => [function () {
                return '2031-01-01';
            }, 'ISO-8601'],
            'without an offset'  => [function () {
                return Carbon::now('UTC')->addDay()->format('Y-m-d\TH:i:s');
            }, 'ISO-8601'],
            'an impossible date' => [function () {
                return '2031-13-45T10:00:00Z';
            }, 'ISO-8601'],
            'february 30th'      => [function () {
                return Carbon::now('UTC')->addDays(10)->format('Y').'-02-30T10:00:00Z';
            }, 'existing ISO-8601'],
            'hour 24'            => [function () {
                return Carbon::now('UTC')->addDays(10)->format('Y-m-d').'T24:30:00Z';
            }, 'existing ISO-8601'],
            'minute 60'          => [function () {
                return Carbon::now('UTC')->addDays(10)->format('Y-m-d').'T10:60:00Z';
            }, 'existing ISO-8601'],
            'blank'              => [function () {
                return '   ';
            }, 'send_at'],
        ];
    }

    public function test_send_at_refused_with_sync_queue()
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();
        $previous = config('queue.default');

        config(['queue.default' => 'sync']);
        try {
            $this->assertRefused(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
            }, 'database queue driver');
        } finally {
            config(['queue.default' => $previous]);
        }

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_a_queue_other_than_the_database_queue_blocks_scheduling_cancelling_and_sending_now()
    {
        $scheduled = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $scheduled->id, 'send_at' => $this->inDays(5)], $this->user);
        $fresh = $this->draftReply(['body' => 'Fresh']);
        $jobs = $this->jobCount();

        $this->withQueueDriver('redis', function () use ($scheduled, $fresh) {
            $this->assertTrue(SendLater::queueCanBeControlled() === false);

            $this->assertRefused(function () use ($fresh) {
                (new SendDraftTool())->handle(['draft_id' => $fresh->id, 'send_at' => $this->inDays(2)], $this->user);
            }, 'database queue driver');
            $this->assertRefused(function () use ($scheduled) {
                (new CancelScheduledSendTool())->handle(['thread_id' => $scheduled->id], $this->user);
            }, 'database queue driver');
            $this->assertRefused(function () use ($scheduled) {
                (new SendDraftTool())->handle(['draft_id' => $scheduled->id], $this->user);
            }, 'database queue driver');
        });

        $this->assertTrue(SendLater::queueCanBeControlled());
        $this->assertSame(Thread::STATE_DRAFT, (int) $fresh->fresh()->state);
        $this->assertTrue(SendLater::isScheduled(Thread::find($scheduled->id)), 'The scheduled message is untouched.');
        $this->assertSame($jobs, $this->jobCount());
    }

    /**
     * Run the callback with the default queue connection set to a driver, then restore the configuration.
     */
    private function withQueueDriver(string $driver, callable $callback): void
    {
        $previousDefault = config('queue.default');
        $previousConnection = config('queue.connections.mcpff_test');

        config(['queue.connections.mcpff_test' => ['driver' => $driver], 'queue.default' => 'mcpff_test']);
        try {
            $callback();
        } finally {
            config(['queue.default' => $previousDefault, 'queue.connections.mcpff_test' => $previousConnection]);
        }
    }

    public function test_send_at_refused_when_the_module_is_inactive()
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();

        $this->withCompanions(false, function () use ($draft) {
            $this->assertRefused(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
            }, 'Scheduling requires the Send Later module.');
        });

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_a_scheduled_send_in_a_trashed_conversation_is_refused()
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();
        $this->conversation->forceFill(['state' => Conversation::STATE_DELETED])->save();

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        }, 'trash');

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_a_new_conversation_can_be_scheduled_and_cancelled()
    {
        $result = (new CreateConversationTool())->handle([
            'mailbox_id' => $this->mailbox->id,
            'to'         => ['new-customer@example.test'],
            'subject'    => 'Scheduled hello',
            'body'       => 'Hello later',
        ], $this->user);
        $sendAt = $this->inDays(4);

        $sent = (new SendDraftTool())->handle(['draft_id' => $result['draft_id'], 'send_at' => $sendAt], $this->user);

        $this->assertSame('scheduled', $sent['state']);
        $this->assertSame($sendAt, $sent['scheduled_at']);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) Conversation::find($result['conversation_id'])->state);

        $cancelled = (new CancelScheduledSendTool())->handle(['thread_id' => $result['draft_id']], $this->user);

        $this->assertSame('draft', $cancelled['state']);
        $this->assertSame(Thread::STATE_DRAFT, (int) Thread::find($result['draft_id'])->state);
        $this->assertSame(Conversation::STATE_DRAFT, (int) Conversation::find($result['conversation_id'])->state);
    }

    public function test_cancel_returns_thread_to_draft()
    {
        $draft = $this->draftReply();
        $jobs = $this->jobCount();
        $threads = (int) Conversation::find($this->conversation->id)->threads_count;
        $sendAt = $this->inDays(2);
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $sendAt], $this->user);
        $this->assertGreaterThan($jobs, $this->jobCount());

        $result = (new CancelScheduledSendTool())->handle(['thread_id' => $draft->id], $this->user);

        $this->assertSame(['draft_id' => (int) $draft->id, 'conversation_id' => (int) $this->conversation->id, 'state' => 'draft'], $result);
        $thread = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_DRAFT, (int) $thread->state);
        $this->assertNull($thread->getMeta('sl.schedule'));
        $conversation = Conversation::find($this->conversation->id);
        $this->assertSame(0, (int) $conversation->scheduled);
        $this->assertSame($threads, (int) $conversation->threads_count);
        // The queued email is gone, so nothing will be sent.
        $this->assertSame($jobs, $this->jobCount());

        // The draft can be changed and scheduled again.
        (new UpdateDraftTool())->handle(['draft_id' => $draft->id, 'body' => 'Better text'], $this->user);
        $again = (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(5)], $this->user);
        $this->assertSame('scheduled', $again['state']);
        $this->assertSame('Better text', strip_tags(Thread::find($draft->id)->body));
    }

    public function test_cancel_refuses_threads_that_are_not_scheduled()
    {
        $draft = $this->draftReply();
        $customerThread = Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_CUSTOMER)->first();
        $jobs = $this->jobCount();

        foreach ([$draft->id, $customerThread->id] as $threadId) {
            $this->assertRefused(function () use ($threadId) {
                (new CancelScheduledSendTool())->handle(['thread_id' => $threadId], $this->user);
            }, 'is not a scheduled message');
        }

        // A message that was sent without a schedule is not cancellable either.
        $sent = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $sent->id], $this->user);
        $afterSend = $this->jobCount();
        $this->assertRefused(function () use ($sent) {
            (new CancelScheduledSendTool())->handle(['thread_id' => $sent->id], $this->user);
        }, 'is not a scheduled message');

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $sent->fresh()->state);
        $this->assertSame($afterSend, $this->jobCount());
        $this->assertGreaterThanOrEqual($jobs, $afterSend);
    }

    public function test_cancel_needs_access_to_the_conversation()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        $jobs = $this->jobCount();

        foreach ([$draft->id, 999999999] as $threadId) {
            $this->assertRefused(function () use ($threadId) {
                (new CancelScheduledSendTool())->handle(['thread_id' => $threadId], $this->outsider);
            }, 'Scheduled message not found or not accessible.');
        }

        $this->assertTrue(SendLater::isScheduled(Thread::find($draft->id)));
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_cancel_is_unavailable_when_the_module_is_inactive()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        $tool = new CancelScheduledSendTool();

        $this->assertTrue($tool->isAvailable($this->user));
        $this->withCompanions(false, function () use ($tool, $draft) {
            $this->assertFalse($tool->isAvailable($this->user));
            $this->assertRefused(function () use ($tool, $draft) {
                $tool->handle(['thread_id' => $draft->id], $this->user);
            }, 'requires the Send Later module');
        });
        $this->assertTrue(SendLater::isScheduled(Thread::find($draft->id)));
    }

    public function test_send_now_on_scheduled_thread()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(7)], $this->user);
        $jobs = $this->jobCount();
        $this->assertGreaterThan(time() + 6 * 86400, (int) $this->emailJob()->available_at);

        $result = (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);

        $this->assertSame('published', $result['state']);
        $this->assertSame((int) $draft->id, $result['thread_id']);
        $this->assertArrayNotHasKey('scheduled_at', $result);
        $thread = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $thread->state);
        $this->assertNull($thread->getMeta('sl.schedule'));
        $this->assertSame(0, (int) Conversation::find($this->conversation->id)->scheduled);
        // The same queued email now runs right away; no second one is queued.
        $this->assertSame($jobs, $this->jobCount());
        $this->assertLessThanOrEqual(time(), (int) $this->emailJob()->available_at);
        $this->assertNull(Presenter::thread($thread)['scheduled_at']);
    }

    public function test_send_now_in_a_trashed_conversation_is_refused()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        $this->conversation->forceFill(['state' => Conversation::STATE_DELETED])->save();

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        }, 'trash');

        $this->assertTrue(SendLater::isScheduled(Thread::find($draft->id)));
        $this->assertGreaterThan(time() + 86400, (int) $this->emailJob()->available_at);
    }

    public function test_send_at_on_an_already_scheduled_thread_is_refused()
    {
        $draft = $this->draftReply();
        $first = $this->inDays(2);
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $first], $this->user);
        $jobs = $this->jobCount();

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(9)], $this->user);
        }, 'Cancel the scheduled send first, then update and reschedule.');

        $this->assertSame($first, Presenter::scheduledAt(Thread::find($draft->id)));
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_send_now_needs_access_to_the_conversation()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->outsider);
        }, 'Draft not found or not accessible.');

        $this->assertTrue(SendLater::isScheduled(Thread::find($draft->id)));
    }

    public function test_a_published_message_without_a_schedule_is_still_not_a_draft()
    {
        $draft = $this->draftReply();
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        $jobs = $this->jobCount();

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        }, 'is not a draft');

        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_cancel_tool_declares_its_annotations()
    {
        $tool = new CancelScheduledSendTool();

        $this->assertSame('cancel_scheduled_send', $tool->name());
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false], $tool->annotations());
        $this->assertSame(['thread_id'], $tool->inputSchema()['required']);
    }

    public function test_cancel_tool_is_listed_only_while_the_module_is_active()
    {
        $names = function () {
            return array_map(function ($tool) {
                return $tool->name();
            }, app(ToolRegistry::class)->available($this->user));
        };

        $this->assertContains('cancel_scheduled_send', $names());
        $this->withCompanions(false, function () use ($names) {
            $this->assertNotContains('cancel_scheduled_send', $names());
        });
    }

    public function test_a_part_minute_rounds_up_and_is_never_sent_early()
    {
        $minute = Carbon::now('UTC')->addDays(2)->setTime(10, 0, 0);
        $next = $minute->copy()->addMinute()->format('Y-m-d H:i:00');
        $user = Fixtures::user(['timezone' => 'UTC']);

        $this->assertSame($minute->format('Y-m-d H:i:00'), SendLater::scheduleField($this->iso($minute->getTimestamp()), $user));
        foreach (['T10:00:01Z', 'T10:00:59Z', 'T10:00:30.9Z', 'T10:00:00.5Z'] as $time) {
            $this->assertSame($next, SendLater::scheduleField($minute->format('Y-m-d').$time, $user), $time);
        }

        // Just over a minute ahead is allowed and is stored at a minute that has not passed yet.
        $soon = Carbon::now('UTC')->addSeconds(61);
        $field = SendLater::scheduleField($this->iso($soon->getTimestamp()), $user);
        $this->assertGreaterThanOrEqual($soon->getTimestamp(), Carbon::createFromFormat('Y-m-d H:i:00', $field, 'UTC')->getTimestamp());
    }

    public function test_a_chat_reply_cannot_be_scheduled_because_only_email_drafts_are_sent()
    {
        $draft = $this->draftReply();
        $this->conversation->forceFill(['type' => Conversation::TYPE_CHAT])->save();
        $jobs = $this->jobCount();

        $this->assertRefused(function () use ($draft) {
            (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        }, 'Only drafts of email conversations can be sent');

        $this->assertSame(Thread::STATE_DRAFT, (int) $draft->fresh()->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_a_repeated_hour_in_the_users_timezone_is_told_apart()
    {
        list($zone, $transition) = $this->nextTransition(false);

        $this->assertOnlyOneOfTheRepeatedInstantsIsAccepted($transition, $zone);
    }

    public function test_a_repeated_hour_in_the_application_timezone_is_told_apart()
    {
        $transitions = (new \DateTimeZone(config('app.timezone')))->getTransitions(time() + 3 * 86400, time() + 360 * 86400);
        $fallBack = array_filter(array_slice($transitions, 1), function ($transition) {
            return !$transition['isdst'];
        });
        if (!$fallBack) {
            $this->markTestSkipped('The application timezone has no clock change within the next year.');
        }

        $this->assertOnlyOneOfTheRepeatedInstantsIsAccepted((int) reset($fallBack)['ts'], 'UTC');
    }

    public function test_instants_around_a_clock_gap_are_scheduled_exactly()
    {
        // Every instant has a wall time, so the skipped hour itself can never be asked for; its neighbours must be exact.
        list($zone, $transition) = $this->nextTransition(true);
        $user = Fixtures::user(['timezone' => $zone]);

        foreach ([$transition - 1800, $transition + 1800] as $instant) {
            $field = SendLater::scheduleField($this->iso($instant), $user);
            $this->assertSame($instant, Carbon::createFromFormat('Y-m-d H:i:00', $field, $zone)->getTimestamp());
        }
    }

    public function test_a_time_in_a_daylight_saving_fold_is_refused_and_nothing_is_sent()
    {
        list($zone, $transition) = $this->nextTransition(false);
        $user = Fixtures::user(['timezone' => $zone]);
        $this->mailbox->users()->attach($user->id);
        $conversation = Fixtures::conversation($this->mailbox, Fixtures::customer());
        $result = (new ReplyToConversationTool())->handle(['conversation_id' => $conversation->id, 'body' => 'Original'], $user);
        $jobs = $this->jobCount();

        $refused = 0;
        foreach ([$transition - 1800, $transition + 1800] as $instant) {
            try {
                SendLater::scheduleField($this->iso($instant), $user);
            } catch (ToolError $e) {
                $refused++;
                $this->assertRefused(function () use ($result, $instant, $user) {
                    (new SendDraftTool())->handle(['draft_id' => $result['draft_id'], 'send_at' => $this->iso($instant)], $user);
                }, 'daylight-saving change in your timezone; choose another time.');
            }
        }

        $this->assertSame(1, $refused);
        $this->assertSame(Thread::STATE_DRAFT, (int) Thread::find($result['draft_id'])->state);
        $this->assertSame($jobs, $this->jobCount());
    }

    public function test_a_schedule_the_module_ignores_is_undone_before_anything_is_sent()
    {
        $draft = $this->draftReply(['status_after_send' => 'closed']);
        $jobs = $this->jobCount();
        // Stands in for the module failing to store the schedule, which its hook does without any error.
        $dropSchedule = function ($thread) {
            $thread->unsetMeta(SendLater::META_NAME);
        };

        \Eventy::addAction('thread.before_save_from_request', $dropSchedule, 30, 2);
        try {
            $this->assertRefused(function () use ($draft) {
                (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
            }, 'Scheduling failed; nothing was sent and the message is a draft again.');
        } finally {
            \Eventy::removeAction('thread.before_save_from_request', $dropSchedule, 30);
        }

        $thread = Thread::find($draft->id);
        $this->assertSame(Thread::STATE_DRAFT, (int) $thread->state);
        $this->assertSame($jobs, $this->jobCount(), 'The queued email was removed.');
        $this->assertFalse(SendLater::hasQueuedJobs($draft->id));
        $this->assertSame(0, (int) Conversation::find($this->conversation->id)->scheduled);
        $this->assertSame(['status' => Conversation::STATUS_CLOSED], DraftChoices::get($thread));
    }

    public function test_cancel_and_reschedule_keep_the_choices_made_for_the_draft()
    {
        $this->requireModule(CompanionModules::CUSTOM_SIGNATURES);
        $signature = new CustomSignature();
        $signature->mailbox_id = $this->mailbox->id;
        $signature->name = 'Sales';
        $signature->text = '<p>Sales team</p>';
        $signature->save();
        $draft = $this->draftReply([
            'signature_id'        => $signature->id,
            'status_after_send'   => 'closed',
            'assignee_after_send' => $this->colleague->id,
        ]);
        $choices = DraftChoices::get($draft);
        $this->assertCount(3, $choices);

        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        $this->assertSame($choices, DraftChoices::get(Thread::find($draft->id)), 'A scheduled message keeps its choices.');

        (new CancelScheduledSendTool())->handle(['thread_id' => $draft->id], $this->user);
        $this->assertSame($choices, Presenter::thread(Thread::find($draft->id))['draft_choices']);

        // Put the conversation back as it was, then schedule the same draft again.
        $this->conversation->forceFill(['status' => Conversation::STATUS_ACTIVE, 'user_id' => null])->save();
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(3)], $this->user);

        $conversation = Conversation::find($this->conversation->id);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertSame((int) $this->colleague->id, (int) $conversation->user_id);
        $this->assertSame((int) $signature->id, (int) Thread::find($draft->id)->getMeta('cs.signature_id'));
    }

    public function test_the_choices_of_a_scheduled_message_are_dropped_when_it_really_goes_out()
    {
        $draft = $this->draftReply(['status_after_send' => 'closed']);
        (new SendDraftTool())->handle(['draft_id' => $draft->id, 'send_at' => $this->inDays(2)], $this->user);
        (new SendDraftTool())->handle(['draft_id' => $draft->id], $this->user);
        $this->assertNotEmpty(DraftChoices::get(Thread::find($draft->id)), 'Sending now only releases the queued email.');

        // What the queued job fires once the email is sent.
        \Eventy::action('conversation.user_replied', Conversation::find($this->conversation->id), Thread::find($draft->id));

        $this->assertSame([], DraftChoices::get(Thread::find($draft->id)));
    }

    public function test_module_ajax_reports_module_errors_and_restores_request_and_user()
    {
        $request = app('request');
        \Auth::setUser($this->colleague);

        try {
            CoreActions::moduleAjax($this->user, SendLater::CONTROLLER, ['action' => 'nonsense']);
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertSame('Unknown action', $e->getMessage());
        }

        $this->assertSame($request, app('request'));
        $this->assertSame((int) $this->colleague->id, (int) \Auth::id());

        $result = CoreActions::moduleAjax($this->user, SendLater::CONTROLLER, ['action' => 'calc', 'period' => 'days', 'number' => 1]);
        $this->assertSame('success', $result['status']);
        $this->assertSame($request, app('request'));
        $this->assertSame((int) $this->colleague->id, (int) \Auth::id());
    }

    private function draftReply(array $extra = []): Thread
    {
        $result = (new ReplyToConversationTool())->handle(array_merge(['conversation_id' => $this->conversation->id, 'body' => 'Original'], $extra), $this->user);

        return Thread::find($result['draft_id']);
    }

    /**
     * A valid send_at some days ahead, on a whole minute so it is stored unchanged.
     */
    private function inDays(int $days): string
    {
        return Carbon::now('UTC')->addDays($days)->setTime(10, 0, 0)->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * The clocks are put back at $transition: the same wall times occur twice, and the module
     * can read a wall time as one instant only, so exactly one of the two is accepted.
     */
    private function assertOnlyOneOfTheRepeatedInstantsIsAccepted(int $transition, string $userZone): void
    {
        $user = Fixtures::user(['timezone' => $userZone]);

        $accepted = 0;
        foreach ([$transition - 1800, $transition + 1800] as $instant) {
            try {
                $field = SendLater::scheduleField($this->iso($instant), $user);
                $accepted++;
                $stored = Carbon::createFromFormat('Y-m-d H:i:00', $field, $userZone)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:00');
                $this->assertSame($instant, Carbon::parse($stored, config('app.timezone'))->getTimestamp(), 'The accepted time is the one the module reads.');
            } catch (ToolError $e) {
                $this->assertStringContainsString('daylight-saving change', $e->getMessage());
            }
        }

        $this->assertSame(1, $accepted);
    }

    /**
     * The next change of the clocks in a zone where the user may be, within the period send_at accepts.
     *
     * @param bool $toSummerTime True for the change that skips an hour, false for the one that repeats an hour.
     *
     * @return array The timezone name and the Unix time of the change.
     */
    private function nextTransition(bool $toSummerTime): array
    {
        // One zone of each hemisphere, so that both kinds of change fall within the next year.
        foreach (['America/New_York', 'Australia/Sydney'] as $timezone) {
            $transitions = (new \DateTimeZone($timezone))->getTransitions(time() + 3 * 86400, time() + 360 * 86400);
            foreach (array_slice($transitions, 1) as $transition) {
                if ($transition['isdst'] === $toSummerTime) {
                    return [$timezone, (int) $transition['ts']];
                }
            }
        }

        $this->markTestSkipped('No clock change within the next year.');
    }

    private function iso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('A ToolError was expected.');
        } catch (ToolError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function jobCount(): int
    {
        return \DB::table('jobs')->count();
    }

    private function customerEmailJobs(): int
    {
        return \DB::table('jobs')->where('payload', 'like', '%SendReplyToCustomer%')->count();
    }

    private function emailJob()
    {
        return \DB::table('jobs')->where('payload', 'like', '%SendReplyToCustomer%')->orderBy('id', 'desc')->first();
    }
}

<?php

namespace Modules\McpForFreescout\Tests;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;

/**
 * Factories for the FreeScout entities used across the test suite.
 *
 * All records are persisted, so call them inside a transaction (see TestCase).
 */
class Fixtures
{
    /**
     * Create an active user.
     */
    public static function user(array $attrs = [], int $role = User::ROLE_USER): User
    {
        $token = uniqid();

        $user = new User();
        $user->fill(array_merge([
            'first_name' => 'Test',
            'last_name'  => 'User',
            'email'      => 'user-'.$token.'@example.test',
            'password'   => bcrypt('secret-'.$token),
            'timezone'   => 'UTC',
            'status'     => User::STATUS_ACTIVE,
        ], $attrs));
        $user->role = $role;
        $user->save();

        return $user;
    }

    /**
     * Create a mailbox and grant the given users access to it.
     *
     * @param User[] $users
     */
    public static function mailbox(array $attrs = [], array $users = []): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->fill(array_merge([
            'name'          => 'Test Mailbox',
            'email'         => 'mailbox-'.uniqid().'@example.test',
            'aliases_reply' => false,
        ], $attrs));
        $mailbox->save();

        $userIds = array_map(function (User $user) {
            return $user->id;
        }, $users);

        $mailbox->users()->sync($userIds);
        $mailbox->syncPersonalFolders($userIds);

        return $mailbox;
    }

    /**
     * Create a customer with a single email address.
     */
    public static function customer(string $email = null): Customer
    {
        return Customer::create($email ?: 'customer-'.uniqid().'@example.test', [
            'first_name' => 'Test',
            'last_name'  => 'Customer',
        ]);
    }

    /**
     * Create a published email conversation holding one customer thread.
     */
    public static function conversation(Mailbox $mailbox, Customer $customer, array $attrs = []): Conversation
    {
        $body = 'Hello, I need some help.';

        $conversation = new Conversation();
        $conversation->forceFill(array_merge([
            'type'           => Conversation::TYPE_EMAIL,
            'subject'        => 'Test conversation',
            'status'         => Conversation::STATUS_ACTIVE,
            'state'          => Conversation::STATE_PUBLISHED,
            'mailbox_id'     => $mailbox->id,
            'customer_id'    => $customer->id,
            'customer_email' => $customer->getMainEmail(),
            'source_via'     => Conversation::PERSON_CUSTOMER,
            'source_type'    => Conversation::SOURCE_TYPE_EMAIL,
            'preview'        => $body,
            'last_reply_at'  => date('Y-m-d H:i:s'),
        ], $attrs));
        $conversation->updateFolder($mailbox);
        $conversation->save();

        $thread = new Thread();
        $thread->forceFill([
            'conversation_id'        => $conversation->id,
            'type'                   => Thread::TYPE_CUSTOMER,
            'status'                 => $conversation->status,
            'state'                  => Thread::STATE_PUBLISHED,
            'body'                   => $body,
            'from'                   => $conversation->customer_email,
            'customer_id'            => $customer->id,
            'source_via'             => Thread::PERSON_CUSTOMER,
            'source_type'            => Thread::SOURCE_TYPE_EMAIL,
            'created_by_customer_id' => $customer->id,
            'first'                  => true,
        ]);
        $thread->save();

        $conversation->threads_count = 1;
        $conversation->save();

        return $conversation;
    }
}

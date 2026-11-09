<?php

namespace Modules\McpForFreescout\Support;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Resolves records the way a tool should: missing and forbidden records are
 * indistinguishable to the caller, so ids cannot be probed.
 */
final class Lookup
{
    /**
     * Find a mailbox the user may view.
     *
     * @throws ToolError When the mailbox does not exist or is not accessible.
     */
    public static function mailbox(int $id, User $user): Mailbox
    {
        $mailbox = Mailbox::find($id);
        if (!$mailbox || !$user->can('view', $mailbox)) {
            throw new ToolError('Mailbox not found or not accessible.');
        }

        return $mailbox;
    }

    /**
     * Find a customer the user may view.
     *
     * Core only restricts customers to the user's mailboxes when
     * `limit_user_customer_visibility` is on. Here the restriction always applies
     * to non-administrators, so customer ids cannot be enumerated.
     *
     * @throws ToolError When the customer does not exist or is not accessible.
     */
    public static function customer(int $id, User $user): Customer
    {
        $customer = Customer::find($id);
        if (!$customer
            || !$user->can('view', $customer)
            || (!$user->isAdmin() && !self::hasConversationIn($customer, $user->mailboxesIdsCanView()))
        ) {
            throw new ToolError('Customer not found or not accessible.');
        }

        return $customer;
    }

    /**
     * Find a conversation the user may view.
     *
     * @throws ToolError When the conversation does not exist or is not accessible.
     */
    public static function conversation(int $id, User $user): Conversation
    {
        $conversation = Conversation::find($id);
        if (!$conversation || !$user->can('view', $conversation)) {
            throw new ToolError('Conversation not found or not accessible.');
        }

        return $conversation;
    }

    private static function hasConversationIn(Customer $customer, array $mailboxIds): bool
    {
        return Conversation::where('customer_id', $customer->id)
            ->whereIn('mailbox_id', $mailboxIds)
            ->exists();
    }
}

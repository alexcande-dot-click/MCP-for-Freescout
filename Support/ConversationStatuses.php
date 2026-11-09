<?php

namespace Modules\McpForFreescout\Support;

use App\Conversation;

/**
 * The conversation status names used in tool arguments.
 */
final class ConversationStatuses
{
    /**
     * Status names mapped to FreeScout's status codes.
     */
    const BY_NAME = [
        'active'  => Conversation::STATUS_ACTIVE,
        'pending' => Conversation::STATUS_PENDING,
        'closed'  => Conversation::STATUS_CLOSED,
        'spam'    => Conversation::STATUS_SPAM,
    ];
}

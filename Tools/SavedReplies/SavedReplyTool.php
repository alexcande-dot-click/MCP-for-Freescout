<?php

namespace Modules\McpForFreescout\Tools\SavedReplies;

use App\Mailbox;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\SavedReplies;
use Modules\SavedReplies\Entities\SavedReply;

/**
 * Base class of the tools that manage the saved replies of the Saved Replies
 * module. They are offered only while that module is active.
 */
abstract class SavedReplyTool extends Tool
{
    /**
     * Longest saved reply name the module's table can store.
     */
    const MAX_NAME_LENGTH = 75;

    /**
     * Longest saved reply text, in bytes, that is accepted.
     */
    const MAX_TEXT_BYTES = 65000;

    public function isAvailable(User $user): bool
    {
        return CompanionModules::isActive(CompanionModules::SAVED_REPLIES);
    }

    /**
     * Find a saved reply the user may read, in the same way the module's reply form does.
     *
     * @throws ToolError When the saved reply does not exist or is not readable by the user.
     */
    protected function findReply(int $replyId, User $user): SavedReply
    {
        return SavedReplies::findReadable($replyId, $user);
    }

    /**
     * Find a mailbox of a saved reply, refusing users who may not change its saved replies.
     *
     * @throws ToolError When the mailbox is not accessible or the user has no right to edit its saved replies.
     */
    protected function mailboxToEdit(int $mailboxId, User $user): Mailbox
    {
        $mailbox = Lookup::mailbox($mailboxId, $user);
        $this->assertCanEdit($mailbox, $user);

        return $mailbox;
    }

    /**
     * @throws ToolError When the user has no right to edit the saved replies of the mailbox.
     */
    protected function assertCanEdit(Mailbox $mailbox, User $user): void
    {
        if (!SavedReply::userCanUpdateMailboxSavedReplies($user, $mailbox)) {
            throw new ToolError('You are not allowed to change the saved replies of this mailbox.');
        }
    }

    /**
     * Check a saved reply name: required, short enough for the column and unused in the mailbox.
     *
     * @param SavedReply|null $current The saved reply being renamed, which may keep its name.
     *
     * @throws ToolError
     */
    protected function validName(string $name, int $mailboxId, SavedReply $current = null): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ToolError('Argument "name" must not be empty.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ToolError('Argument "name" must be at most '.self::MAX_NAME_LENGTH.' characters.');
        }

        $taken = SavedReply::where('mailbox_id', $mailboxId)->where('name', $name);
        if ($current) {
            $taken->where('id', '!=', $current->id);
        }
        if ($taken->exists()) {
            throw new ToolError('A saved reply with this name already exists in this mailbox.');
        }

        return $name;
    }

    /**
     * Turn the saved reply text into HTML and purify it as core does for the mailbox signature.
     *
     * @throws ToolError When the text is too long or nothing is left after sanitising.
     */
    protected function sanitisedText(string $text): string
    {
        $this->assertTextFits($text);
        $html = BodyFormatter::purifiedHtml($text);
        if ($html === '') {
            throw new ToolError('Argument "text" must not be empty.');
        }
        $this->assertTextFits($html);

        return $html;
    }

    /**
     * Check the parent of a saved reply: a saved reply of the same mailbox that is neither the reply itself nor one of its descendants.
     *
     * @param SavedReply|null $current The saved reply being moved, if it exists already.
     *
     * @return int|null The parent id to store; null for the top level (the argument 0).
     *
     * @throws ToolError
     */
    protected function validParent(int $parentId, int $mailboxId, SavedReply $current = null): ?int
    {
        if ($parentId === 0) {
            return null;
        }

        $parent = SavedReplies::find($parentId);
        if (!$parent || (int) $parent->mailbox_id !== $mailboxId) {
            throw new ToolError('Argument "parent_id" must be a saved reply of the same mailbox, or 0 for the top level.');
        }
        if ($current) {
            $siblings = SavedReply::where('mailbox_id', $mailboxId)->get()->all();
            if ((int) $current->id === $parentId || $current->isChild($parentId, $siblings)) {
                throw new ToolError('Argument "parent_id" would make a saved reply its own ancestor.');
            }
        }

        return $parentId;
    }

    /**
     * @throws ToolError When the text exceeds the size limit.
     */
    private function assertTextFits(string $text): void
    {
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            throw new ToolError('Argument "text" must be at most '.self::MAX_TEXT_BYTES.' bytes.');
        }
    }
}

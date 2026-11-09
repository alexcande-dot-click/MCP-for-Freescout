<?php

namespace Modules\McpForFreescout\Tools\Signatures;

use App\Mailbox;
use App\User;
use Modules\CustomSignatures\Entities\CustomSignature;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\CompanionModules;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Signatures;

/**
 * Base class of the tools that manage the signatures of the Custom Mailbox
 * Signatures module. They are offered only while that module is active.
 */
abstract class SignatureTool extends Tool
{
    /**
     * Longest signature name the module's table can store.
     */
    const MAX_NAME_LENGTH = 75;

    public function isAvailable(User $user): bool
    {
        return CompanionModules::isActive(CompanionModules::CUSTOM_SIGNATURES);
    }

    /**
     * Find a signature in a mailbox the user may view.
     *
     * Missing and inaccessible signatures give the same answer, so ids cannot be probed.
     *
     * @throws ToolError When the signature does not exist or its mailbox is not accessible.
     */
    protected function findSignature(int $signatureId, User $user): CustomSignature
    {
        $signature = Signatures::find($signatureId);
        $mailbox = $signature ? Mailbox::find($signature->mailbox_id) : null;
        if (!$signature || !$mailbox || !$user->can('view', $mailbox)) {
            throw new ToolError('Signature not found or not accessible.');
        }

        return $signature;
    }

    /**
     * Find a mailbox of a signature, refusing users who may not change its signatures.
     *
     * @throws ToolError When the user has no right to edit the mailbox signatures.
     */
    protected function mailboxToEdit(int $mailboxId, User $user): Mailbox
    {
        $mailbox = Lookup::mailbox($mailboxId, $user);
        if (!$user->can('updateEmailSignature', $mailbox)) {
            throw new ToolError('You are not allowed to change the signatures of this mailbox.');
        }

        return $mailbox;
    }

    /**
     * Check a signature name: required, and short enough for the column.
     *
     * @throws ToolError
     */
    protected function validName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ToolError('Argument "name" must not be empty.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ToolError('Argument "name" must be at most '.self::MAX_NAME_LENGTH.' characters.');
        }

        return $name;
    }

    /**
     * Turn the signature text into HTML and purify it as core does for the mailbox signature.
     *
     * @throws ToolError When nothing is left after sanitising.
     */
    protected function sanitisedText(string $text): string
    {
        $html = BodyFormatter::purifiedHtml($text);
        if ($html === '') {
            throw new ToolError('Argument "text" must not be empty.');
        }

        return $html;
    }
}

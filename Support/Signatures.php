<?php

namespace Modules\McpForFreescout\Support;

use App\Mailbox;
use Modules\CustomSignatures\Entities\CustomSignature;

/**
 * Access to the signatures of the Custom Mailbox Signatures module.
 *
 * Every method needs the module to be active; callers check
 * CompanionModules::isActive(CompanionModules::CUSTOM_SIGNATURES) first.
 */
final class Signatures
{
    /**
     * Find a signature by id.
     */
    public static function find(int $id): ?CustomSignature
    {
        return CustomSignature::find($id);
    }

    /**
     * Whether a signature exists and belongs to the mailbox.
     */
    public static function belongsToMailbox(int $id, Mailbox $mailbox): bool
    {
        $signature = self::find($id);

        return $signature !== null && (int) $signature->mailbox_id === (int) $mailbox->id;
    }
}

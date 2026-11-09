<?php

namespace Modules\McpForFreescout\Tools\Signatures;

use App\User;

class DeleteSignatureTool extends SignatureTool
{
    public function name(): string
    {
        return 'delete_signature';
    }

    public function description(): string
    {
        return 'Permanently delete a custom signature. Drafts that chose it must get another one with update_draft before they can be sent. '
            .'Needs the permission to change the signatures of the mailbox.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'signature_id' => ['type' => 'integer', 'description' => 'Signature id, from list_signatures.'],
            ],
            'required'             => ['signature_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $signature = $this->findSignature($this->requireInt($arguments, 'signature_id'), $user);
        $this->mailboxToEdit($signature->mailbox_id, $user);

        $signature->deleteSignature();

        return ['deleted' => true, 'signature_id' => (int) $signature->id];
    }
}

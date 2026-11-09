<?php

namespace Modules\McpForFreescout\Tools\Signatures;

use App\User;
use Modules\CustomSignatures\Entities\CustomSignature;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class ListSignaturesTool extends SignatureTool
{
    public function name(): string
    {
        return 'list_signatures';
    }

    public function description(): string
    {
        return 'List the custom signatures of a mailbox with their full text. '
            .'The id can be passed as signature_id to reply_to_conversation, create_conversation and update_draft.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Mailbox id, from list_mailboxes.'],
            ],
            'required'             => ['mailbox_id'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailbox = Lookup::mailbox($this->requireInt($arguments, 'mailbox_id'), $user);

        $signatures = CustomSignature::where('mailbox_id', $mailbox->id)
            ->orderBy('id')
            ->get()
            ->map(function (CustomSignature $signature) {
                $presented = Presenter::signature($signature);
                unset($presented['mailbox_id']);

                return $presented;
            })
            ->all();

        return ['mailbox_id' => (int) $mailbox->id, 'signatures' => $signatures];
    }
}

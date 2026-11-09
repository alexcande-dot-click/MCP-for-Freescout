<?php

namespace Modules\McpForFreescout\Tools\Signatures;

use App\User;
use Modules\CustomSignatures\Entities\CustomSignature;
use Modules\McpForFreescout\Support\Presenter;

class CreateSignatureTool extends SignatureTool
{
    public function name(): string
    {
        return 'create_signature';
    }

    public function description(): string
    {
        return 'Add a custom signature to a mailbox. The text may be plain text or HTML and can use FreeScout variables such as {%user.first_name%}. '
            .'Needs the permission to change the signatures of the mailbox. Returns the new signature.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id' => ['type' => 'integer', 'description' => 'Mailbox id, from list_mailboxes.'],
                'name'       => ['type' => 'string', 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'Name shown in the signature selector.'],
                'text'       => ['type' => 'string', 'description' => 'Signature text, plain text or HTML.'],
            ],
            'required'             => ['mailbox_id', 'name', 'text'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailbox = $this->mailboxToEdit($this->requireInt($arguments, 'mailbox_id'), $user);
        $name = $this->validName($this->requireString($arguments, 'name'));
        $text = $this->sanitisedText($this->requireString($arguments, 'text'));

        $signature = new CustomSignature();
        $signature->mailbox_id = $mailbox->id;
        $signature->name = $name;
        $signature->text = $text;
        $signature->save();

        return Presenter::signature($signature);
    }
}

<?php

namespace Modules\McpForFreescout\Tools\Signatures;

use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Presenter;

class UpdateSignatureTool extends SignatureTool
{
    public function name(): string
    {
        return 'update_signature';
    }

    public function description(): string
    {
        return 'Change the name and/or the text of a custom signature. Give at least one of name and text. '
            .'Needs the permission to change the signatures of the mailbox. Returns the updated signature.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'signature_id' => ['type' => 'integer', 'description' => 'Signature id, from list_signatures.'],
                'name'         => ['type' => 'string', 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'New name.'],
                'text'         => ['type' => 'string', 'description' => 'New text, plain text or HTML.'],
            ],
            'required'             => ['signature_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $signature = $this->findSignature($this->requireInt($arguments, 'signature_id'), $user);
        $this->mailboxToEdit($signature->mailbox_id, $user);

        $name = $this->optionalString($arguments, 'name');
        $text = $this->optionalString($arguments, 'text');
        if ($name === null && $text === null) {
            throw new ToolError('Give at least one of name or text.');
        }

        if ($name !== null) {
            $signature->name = $this->validName($name);
        }
        if ($text !== null) {
            $signature->text = $this->sanitisedText($text);
        }
        $signature->save();

        return Presenter::signature($signature);
    }
}

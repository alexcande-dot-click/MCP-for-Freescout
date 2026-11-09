<?php

namespace Modules\McpForFreescout\Tools\Mailboxes;

use App\Email;
use App\Mailbox;
use App\User;
use Modules\McpForFreescout\Mcp\Tool;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\BodyFormatter;
use Modules\McpForFreescout\Support\Lookup;
use Modules\McpForFreescout\Support\Presenter;

class UpdateMailboxTool extends Tool
{
    /**
     * Longest mailbox name accepted by FreeScout's mailbox settings form.
     */
    const MAX_NAME_LENGTH = 40;

    public function name(): string
    {
        return 'update_mailbox';
    }

    public function description(): string
    {
        return 'Change a mailbox: its name (administrators only), reply-from aliases, the switch that allows replying from aliases, '
            .'and/or its main signature. Give at least one of name, aliases, aliases_reply and signature. '
            .'Name, aliases and aliases_reply need the mailbox settings permission, the signature needs the signature permission; '
            .'if any requested change is not allowed, nothing is changed. Returns the updated mailbox.';
    }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'mailbox_id'    => ['type' => 'integer', 'description' => 'Mailbox id, from list_mailboxes.'],
                'name'          => ['type' => 'string', 'maxLength' => self::MAX_NAME_LENGTH, 'description' => 'New mailbox name. Administrators only.'],
                'aliases'       => [
                    'type'        => 'string',
                    'description' => 'Comma-separated alias email addresses, each optionally followed by a display name in '
                        .'parentheses, e.g. "sales@example.com (Sales), help@example.com". Replaces the whole list; an empty string removes all aliases.',
                ],
                'aliases_reply' => ['type' => 'boolean', 'description' => 'Whether replies may be sent from the aliases.'],
                'signature'     => ['type' => 'string', 'description' => 'New main signature, plain text or HTML. An empty string removes it.'],
            ],
            'required'             => ['mailbox_id'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true];
    }

    public function handle(array $arguments, User $user): array
    {
        $mailboxId = $this->requireInt($arguments, 'mailbox_id');
        $name = $this->optionalString($arguments, 'name');
        $aliases = $this->optionalString($arguments, 'aliases');
        $aliasesReply = $this->optionalBool($arguments, 'aliases_reply');
        $signature = $this->optionalString($arguments, 'signature');

        $changesSettings = $name !== null || $aliases !== null || $aliasesReply !== null;
        if (!$changesSettings && $signature === null) {
            throw new ToolError('Give at least one of name, aliases, aliases_reply or signature.');
        }

        $mailbox = Lookup::mailbox($mailboxId, $user);

        // Every requested group is checked before anything is written.
        if ($changesSettings && !$user->can('updateSettings', $mailbox)) {
            throw new ToolError('You are not allowed to change the settings of this mailbox.');
        }
        if ($name !== null && !$user->isAdmin()) {
            // Core ignores the submitted name of a non-administrator.
            throw new ToolError('Only administrators can rename a mailbox.');
        }
        if ($signature !== null && !$user->can('updateEmailSignature', $mailbox)) {
            throw new ToolError('You are not allowed to change the signature of this mailbox.');
        }

        // Values are validated up front too, so a bad one cannot leave a half-applied update.
        $name = $name === null ? null : $this->validName($name);
        $aliases = $aliases === null ? null : $this->normalisedAliases($aliases);

        // The core save path (MailboxesController@updateSave) is deliberately not used: it fires
        // "mailbox.settings_before_save", where companion modules such as Custom Signatures read
        // their own form fields and delete whatever is absent from the request. A call that only
        // touches four fields would wipe their data, so the attributes are set and saved directly.
        if ($name !== null) {
            $mailbox->name = $name;
        }
        if ($aliases !== null) {
            $mailbox->aliases = $aliases === '' ? null : $aliases;
        }
        if ($aliasesReply !== null) {
            $mailbox->aliases_reply = $aliasesReply;
        }
        if ($signature !== null) {
            $mailbox->signature = $this->sanitisedSignature($signature);
        }
        $mailbox->save();

        \Eventy::action('mcpforfreescout.mailbox_updated', $mailbox, $user);

        return Presenter::mailbox($mailbox, $user);
    }

    /**
     * Apply core's rules for the mailbox name: required, at most 40 characters.
     *
     * @throws ToolError
     */
    private function validName(string $name): string
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
     * Validate a comma-separated alias list and bring it into the form core stores.
     *
     * Core keeps the raw text of the settings field and parses it with
     * Mailbox::getAliases() when it is used, so the stored value must stay parseable
     * by that method: "email" or "email (Name)", separated by commas. The addresses
     * are lowercased and cleaned like core does, and duplicates are dropped.
     *
     * @return string The list to store; an empty string when no alias is left.
     *
     * @throws ToolError When an entry is not an email address with an optional name.
     */
    private function normalisedAliases(string $aliases): string
    {
        $normalised = [];
        foreach (explode(',', $aliases) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (!preg_match('/^([^()\s,]+@[^()\s,]+)\s*(?:\(([^(),]*)\))?$/u', $entry, $match)) {
                throw new ToolError('Argument "aliases" must be comma-separated email addresses, each optionally followed by a name in parentheses; invalid entry: "'.$entry.'".');
            }

            $email = Email::sanitizeEmail($match[1]);
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ToolError('Argument "aliases" contains an invalid email address: "'.$match[1].'".');
            }
            if (isset($normalised[$email])) {
                continue;
            }

            $displayName = isset($match[2]) ? trim($match[2]) : '';
            $normalised[$email] = $displayName === '' ? $email : $email.' ('.$displayName.')';
        }

        return implode(', ', $normalised);
    }

    /**
     * Turn the signature into HTML and purify it exactly as core does on save.
     *
     * @return string|null The signature to store; null when it is blank.
     */
    private function sanitisedSignature(string $signature)
    {
        $signature = trim($signature);
        if ($signature === '') {
            return null;
        }

        return Mailbox::sanitizeSignature(BodyFormatter::toHtml($signature));
    }
}

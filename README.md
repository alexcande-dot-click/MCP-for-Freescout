# MCP for FreeScout

A [FreeScout](https://freescout.net) module that embeds a [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server in your help desk. An AI agent connects with a personal bearer token and works as that FreeScout user: it sees only what the user may see, prepares replies as drafts, and sends an email only through a separate, explicit step.

- [Why this module exists](#why-this-module-exists)
- [Features](#features)
- [Safety model](#safety-model)
- [Requirements](#requirements)
- [Installation](#installation)
- [Tokens and endpoint](#tokens-and-endpoint)
- [Connecting a client](#connecting-a-client)
- [Tool reference](#tool-reference)
- [Companion modules and behaviour worth knowing](#companion-modules-and-behaviour-worth-knowing)
- [Security notes](#security-notes)
- [Development](#development)
- [License](#license)

## Why this module exists

FreeScout's official API (the paid "API & Webhooks" module) cannot choose the **From alias** or the **signature** of a reply. Those are basic needs for an agent that handles support mail on behalf of a team. This module closes the gap: it exposes FreeScout through MCP, runs every action through the same code path as the web interface, and so honours aliases, signatures and the hooks of other modules (Send Later, Custom Signatures and so on) exactly as if a person had clicked the buttons.

## Features

- MCP endpoint at `/mcp` using Streamable HTTP with bearer-token authentication. Stateless: every request is answered with `application/json`, no sessions, no SSE streams.
- One token per user (a user may hold several). The agent acts as that user, with that user's permissions, and every change is attributed to that user in FreeScout's history.
- Full support workflow: read mailboxes and conversations, search, write reply and new-email drafts with a chosen From alias, signature, To/CC/BCC and status/assignee to apply on send, add notes, change status, assignee and subject, move conversations to the trash and restore them.
- Light administration: edit a mailbox (name, reply-from aliases, main signature).
- Integration with four official companion modules when they are installed and active: Custom Mailbox Signatures, Saved Replies, Tags and Send Later.
- Token management inside FreeScout: users manage their own tokens from their profile, administrators can see and revoke every token.
- A global switch to turn the endpoint off, and an audit log of every tool call that changes data.

## Safety model

The module is built so that a mistaken or manipulated agent cannot do irreversible damage by itself.

- **Draft first, send second.** `reply_to_conversation` and `create_conversation` never send. They save a real FreeScout draft, visible in the web interface like any draft. `send_draft` is the only tool that makes an email leave the system, and it is a separate call.
- **Forward drafts are sent from the interface.** Forward drafts cannot be edited or sent through MCP; a person reviews and sends them from the FreeScout web interface.
- **Trash only.** `delete_conversation` moves a conversation to the trash and `restore_conversation` brings it back. There is no tool to empty the trash and none to delete a conversation permanently. (Custom signatures, saved replies and tags can be deleted, when the user has the right to.)
- **Per-user tokens that inherit FreeScout permissions.** A token authenticates as one FreeScout user. Every tool applies the same policies as the web interface (mailbox access, conversation visibility, settings rights). An action on an object the user may not touch returns a tool error, and tools of a companion module are hidden while the module is inactive. Disabling or deleting a user stops that user's tokens immediately.
- **Re-validation at send time.** `send_draft` checks everything again at the moment of sending, not as it was when the draft was written: the user's right to update the conversation, that the conversation is not in the trash, that the From alias is still allowed for the mailbox, and that the chosen signature and saved reply still exist.
- **Audit log.** Every successful tool call that is not read-only writes an entry to FreeScout's activity log under the log name `mcpforfreescout` (Manage > Logs). An entry holds the tool name, the id of the user, the token prefix (for example `fsmcp_9a8b6e`, enough to tell tokens apart) and the **ids** found in the arguments (`conversation_id`, `mailbox_id`, `draft_id`, `tag_id`, `saved_reply_id`, and so on). It never holds message bodies, subjects, email addresses or names, and tag names are not recorded either: for `add_tags` and `remove_tags` only the conversation id is logged, not which tags changed. Failed calls are not logged, and read-only calls are not logged.

## Requirements

- FreeScout 1.8.250 or later (`requiredAppVersion` in `module.json`), the version the module is developed and tested against.
- PHP 7.1 or later. The code uses PHP 7.1 syntax only; the test suite runs on PHP 8.2.
- A running queue worker. FreeScout's regular cron entry (`php artisan schedule:run` every minute) starts it. A worker is required for emails to be sent at all, and for Send Later.
- Send Later control through MCP requires the `database` queue driver (the module only manages the `jobs` table). With any other driver (`sync`, `redis`, `sqs`, `beanstalkd`), `send_at`, `cancel_scheduled_send` and sending a scheduled message now are refused and nothing is changed.
- HTTPS for the public endpoint (see [Security notes](#security-notes)).

## Installation

1. Download `McpForFreescout-<version>.zip` from the [Releases](https://github.com/alexcande-dot-click/mcp-for-freescout/releases) page of the repository.
2. Extract it into the `Modules` directory of your FreeScout installation, so that `Modules/McpForFreescout/module.json` exists.
3. In FreeScout, open **Manage > Modules** and activate **MCP for FreeScout**. The module creates its database table on activation.

The endpoint is available straight away. To update, replace the `Modules/McpForFreescout` directory with the new release and clear FreeScout's cache.

## Tokens and endpoint

**Creating a token.** Open your profile, choose **MCP tokens**, give the token a label and create it. The token (it starts with `fsmcp_`) is shown **once**; copy it immediately. Only a hash is stored, so a lost token cannot be recovered, only replaced. A user can hold several tokens, for example one per agent or device, and see when each was last used.

**Revoking a token.** Revoke it from the same page; clients using it lose access at once. Administrators see every user's tokens under **Manage > MCP tokens** and can revoke any of them (but cannot create tokens for other users).

**Enabling and disabling the endpoint.** **Manage > Settings > MCP** has a switch for the whole endpoint. While it is off, `/mcp` answers every request with HTTP 503. The setting page also shows the endpoint URL.

**Endpoint URL.** `https://<your-helpdesk>/mcp`. If FreeScout runs in a subdirectory, the URL includes it (`https://example.com/helpdesk/mcp`). Only `POST` is accepted.

## Connecting a client

### OpenClaw

```bash
openclaw mcp set freescout '{"url":"https://helpdesk.example.com/mcp","transport":"streamable-http","headers":{"Authorization":"Bearer fsmcp_…"}}'
```

### Any Streamable HTTP client

Point the client at the endpoint URL and send the token in the `Authorization` header. A typical configuration looks like this (the exact keys depend on the client):

```json
{
  "mcpServers": {
    "freescout": {
      "type": "http",
      "url": "https://helpdesk.example.com/mcp",
      "headers": {
        "Authorization": "Bearer fsmcp_…"
      }
    }
  }
}
```

### Protocol versions

| Version | Style |
|---|---|
| `2026-07-28` | Stateless. No handshake: `server/discover`, the protocol version in `params._meta`, and the `MCP-Protocol-Version`, `Mcp-Method` and `Mcp-Name` headers. |
| `2025-11-25` | `initialize` handshake, answered statelessly (no session id). |
| `2025-06-18` | `initialize` handshake, answered statelessly (no session id). |

The server declares the `tools` capability only. Tool results carry both a text block and `structuredContent`. Tools declare their input schema and annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, and `openWorldHint` on `send_draft`).

## Tool reference

Tools marked with a companion module appear in `tools/list` only while that module is installed and active. Parameters that belong to a companion module (`signature_id`, `saved_reply_id`, `send_at`, the `tag` filter of `list_conversations`) are accepted only while the module is active.

### Mailboxes

| Tool | Purpose | Companion module |
|---|---|---|
| `list_mailboxes` | Mailboxes the user can access, with email addresses, reply-from aliases, signatures and the user's permissions. Mailbox ids are needed by most other tools. | |
| `get_mailbox` | One mailbox in detail. | |
| `update_mailbox` | Change name (administrators only), reply-from aliases, the switch that allows replying from aliases, and/or the main signature. Each change is checked against the matching permission. | |

### Conversations

| Tool | Purpose | Companion module |
|---|---|---|
| `list_conversations` | List conversations of the user's mailboxes with filters (mailbox, folder, status, assignee, tag) and paging, newest reply first. | Tags (for the `tag` filter) |
| `search_conversations` | Search like the FreeScout search box: subject, customer, number, message text, senders and recipients; optional mailbox and status filters. | |
| `get_conversation` | One conversation with messages, notes, the caller's own drafts, attachment names and sizes, tags and scheduled state. | |
| `update_conversation` | Change status, assignee (an active user with access to the mailbox, or 0 to unassign) and/or subject; recorded in the history as the user. | |
| `add_note` | Add an internal note. Published immediately, never sent to the customer. | |
| `delete_conversation` | Move a conversation to the trash. Never permanent. | |
| `restore_conversation` | Bring a conversation back from the trash. | |

### Drafts and sending

| Tool | Purpose | Companion module |
|---|---|---|
| `reply_to_conversation` | Write a reply as a draft (body or saved reply, From alias, To, CC, BCC, signature, status and assignee to apply on send). Sends nothing. | Custom Signatures (`signature_id`), Saved Replies (`saved_reply_id`) |
| `create_conversation` | Start a new outgoing email conversation as a draft. Sends nothing. | Custom Signatures, Saved Replies |
| `update_draft` | Change an unsent draft; only the given fields change. An explicit `null` for `signature_id`, `status_after_send`, `assignee_after_send` or `saved_reply_id` clears that stored choice (also while its companion module is inactive), so a stale choice never blocks a draft. | Custom Signatures, Saved Replies |
| `discard_draft` | Delete an unsent draft. Published messages are never touched; the draft of a new conversation is refused if its conversation already holds sent or received messages. | |
| `send_draft` | Send a draft, the only tool that sends email. Only drafts of email conversations are sent (chat and phone drafts are refused), and a forward draft is refused. With `send_at` it schedules the message instead; given an already scheduled message and no `send_at`, it sends it right away. Both need the `database` queue driver. | Send Later (`send_at`) |

### Directory

| Tool | Purpose | Companion module |
|---|---|---|
| `list_users` | Active users the caller can see, for choosing an assignee; optionally limited to a mailbox. | |
| `search_customers` | Search customers by name, email address or company. | |
| `get_customer` | One customer: name, emails, phones, company. | |

### Custom Signatures

| Tool | Purpose | Companion module |
|---|---|---|
| `list_signatures` | Custom signatures of a mailbox, with full text. | Custom Mailbox Signatures |
| `create_signature` | Add a signature to a mailbox. | Custom Mailbox Signatures |
| `update_signature` | Change a signature's name and/or text. | Custom Mailbox Signatures |
| `delete_signature` | Delete a signature. | Custom Mailbox Signatures |

### Saved Replies

| Tool | Purpose | Companion module |
|---|---|---|
| `list_saved_replies` | Saved replies a mailbox's reply form offers, as a tree of categories and replies (texts left out). | Saved Replies |
| `get_saved_reply` | One saved reply with its stored text and attached files. | Saved Replies |
| `create_saved_reply` | Add a saved reply to a mailbox. Files cannot be attached. | Saved Replies |
| `update_saved_reply` | Change name, text, parent or global flag. | Saved Replies |
| `delete_saved_reply` | Delete a saved reply with its files. | Saved Replies |

### Tags

| Tool | Purpose | Companion module |
|---|---|---|
| `list_tags` | Tags with colour and conversation count, optionally filtered by name. | Tags |
| `add_tags` | Add tags to a conversation; unknown names create the tag. | Tags |
| `remove_tags` | Remove tags from a conversation. | Tags |
| `create_tag` | Create a tag. | Tags |
| `update_tag` | Rename a tag and/or change its colour. | Tags |
| `delete_tag` | Delete a tag and remove it from all conversations. | Tags |

### Send Later

| Tool | Purpose | Companion module |
|---|---|---|
| `cancel_scheduled_send` | Cancel a scheduled send; the message becomes a draft again. Needs the `database` queue driver. | Send Later |

## Companion modules and behaviour worth knowing

The module integrates with the official Custom Mailbox Signatures, Saved Replies, Tags and Send Later modules through their public data and hooks. None of their code is bundled or copied. Each integration is active only while the companion module is installed and active.

- **Draft choices survive human review.** FreeScout drafts store only body, recipients and From alias. The signature, status and assignee an agent chose are kept by this module next to the draft, and the FreeScout reply editor pre-selects them when a person opens the draft to review it. Opening a draft again re-applies the agent's choices; whatever is selected when the person presses Send wins, as with any reply. The stored choices are cleared when the draft is sent or discarded, or one by one with `update_draft` and an explicit `null` (`draft_choices` in tool results is an object, `{}` when empty).
- **CC defaults like the reply form.** A reply without `cc` copies the conversation's CC recipients, as the web interface does. An empty `cc` list means no CC.
- **One `to` per reply.** A reply has a single `to` recipient (the customer by default). Further recipients go in `cc`.
- **Email conversations only.** Replies can be drafted and sent for email conversations only; `send_draft` refuses drafts of chat and phone conversations.
- **Saved replies as draft bodies.** Passing `saved_reply_id` uses the saved reply's text as the body and copies its attached files onto the draft, like inserting it in the web interface. `{%custom_field.*%}` placeholders are not replaced when a draft is built from a saved reply; the conversation, mailbox, customer and user variables are.
- **Scheduling.** `send_at` is an ISO-8601 time with a UTC offset, between one minute and one year ahead. It is rounded up to the next whole minute (never earlier than requested) and refused when it falls in a daylight-saving change of the user's timezone, because the time would be ambiguous. A scheduled message cannot be rescheduled directly: cancel it with `cancel_scheduled_send`, which turns it back into a draft, then send the draft again.
- **Customers.** Unless the user is an administrator, customers are visible only through conversations in the user's own mailboxes.
- **Notifications.** FreeScout's internal notifications to agents (assignment, new replies, and so on) fire as they do for actions taken in the web interface.
- **The undo-send delay applies.** `send_draft` goes through FreeScout's normal sending flow and queue, including the configured undo period.

## Security notes

- **Use HTTPS.** Tokens travel in the `Authorization` header; over plain HTTP anyone on the path can read them. Put the helpdesk behind TLS.
- **Treat tokens like passwords.** A token grants everything its user can do in FreeScout, including sending email through `send_draft`. Keep it in a secret store, give each agent its own token, and use a dedicated, least-privileged FreeScout user for an agent when possible. Revoke tokens that are no longer used.
- **Tokens are stored hashed.** Only a SHA-256 hash is saved; the plain token is shown once at creation. Tokens are compared in constant time.
- **Origin validation.** Requests carrying an `Origin` header must come from the application's own origin (`APP_URL`); anything else is rejected with 403, which blocks DNS rebinding and cross-site calls from browsers. Clients that send no `Origin` header (CLI tools and servers) are not affected.
- **Switch it off.** If you do not need the endpoint, leave the module inactive or turn the endpoint off in **Manage > Settings > MCP**.

## Development

### Repository layout

```
module.json            Module manifest
start.php              Module bootstrap
Providers/             Service provider: tool registration, hooks, menus
Http/                  Routes and controllers (MCP endpoint, token pages)
Mcp/                   Transport-independent MCP server: JSON-RPC, protocol, tool registry
Tools/                 One class per tool, grouped by area
Support/               Shared services (presenters, lookups, core actions, companion modules)
Entities/              Token model
Database/Migrations/   Token table
Resources/             Views and translations
Public/                Module CSS and JavaScript
Tests/                 PHPUnit tests (not part of release archives)
```

### Running the tests

The tests run inside a real FreeScout installation, against its database; each test runs in a transaction that is rolled back. Use a development instance, not production.

1. Install FreeScout 1.8.x (a checkout or a normal installation) and put this repository in its `Modules` directory as `McpForFreescout`.
2. Activate the module. To run the companion-module tests, install and activate the companion modules as well; those tests are skipped when a module is missing.
3. Download the [PHPUnit 9.6 PHAR](https://phpunit.de/getting-started/phpunit-9.html) and run it with `FREESCOUT_PATH` pointing at the FreeScout directory:

```bash
export FREESCOUT_PATH=/var/www/freescout
php phpunit.phar -c phpunit.xml.dist
```

Release archives are built with `git archive`; `.gitattributes` leaves `Tests/` and the development files out.

### Contributing

Contributions are welcome. Open an issue to discuss a change first, keep to PHP 7.1 syntax, and add tests for new behaviour. The safety model above (draft first, nothing permanent, FreeScout permissions) is the core of the project and changes must not weaken it.

## License

MCP for FreeScout is licensed under the [GNU Affero General Public License v3.0](LICENSE).

Author: [Maumar.Click](https://maumar.click). Repository: <https://github.com/alexcande-dot-click/mcp-for-freescout>.

This project is not affiliated with or endorsed by FreeScout. "FreeScout" is the name of the help desk this module extends.

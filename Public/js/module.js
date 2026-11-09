/**
 * MCP for FreeScout: token management pages.
 */

// Entry point, called from the page footer on the token pages.
function mcpInitTokens()
{
	$(document).ready(function() {
		var $page = $('.mcp-tokens');
		if (!$page.length) {
			return;
		}

		mcpInitCreate($page);
		mcpInitCopy($page);
		mcpInitRevoke($page);
	});
}

// Create a token and show its plaintext once.
function mcpInitCreate($page)
{
	var $form = $page.find('.mcp-token-form');
	if (!$form.length) {
		return;
	}
	var $label = $form.find('#mcp-token-label');
	var $created = $page.find('.mcp-token-created');

	$form.on('submit', function(e) {
		e.preventDefault();

		var $button = $form.find('button[type="submit"]');
		$button.button('loading');

		fsAjax(
			{
				action: 'create',
				user_id: $page.attr('data-user-id'),
				label: $label.val()
			},
			laroute.route('mcpforfreescout.tokens.ajax'),
			function(response) {
				$button.button('reset');
				if (!isAjaxSuccess(response)) {
					showAjaxError(response);
					loaderHide();
					return;
				}
				loaderHide();
				$label.val('');
				$page.find('.mcp-tokens-list').html(response.html);
				mcpShowCreated($page, $created, response.token);
				showAjaxResult(response);
			}, true,
			function() {
				$button.button('reset');
				showFloatingAlert('error', Lang.get('messages.ajax_error'));
				ajaxFinish();
			}
		);
	});

	$created.find('.mcp-token-dismiss').on('click', function() {
		mcpClearCreated($created);
	});
}

// Fill the "copy it now" panel with the new token and the OpenClaw command.
function mcpShowCreated($page, $created, token)
{
	var config = {
		url: $page.attr('data-endpoint'),
		transport: 'streamable-http',
		headers: {Authorization: 'Bearer ' + token}
	};

	$created.find('.mcp-token-plaintext').val(token);
	$created.find('.mcp-token-snippet').val("openclaw mcp set freescout '" + JSON.stringify(config) + "'");
	$created.removeClass('hidden');
	$created.find('.mcp-token-plaintext').focus().select();
}

// Forget the plaintext as soon as the panel is dismissed.
function mcpClearCreated($created)
{
	$created.find('.mcp-token-plaintext, .mcp-token-snippet').val('');
	$created.addClass('hidden');
}

// Copy buttons next to the read-only fields.
function mcpInitCopy($page)
{
	$page.on('click', '.mcp-copy', function() {
		var $source = $page.find($(this).attr('data-copy-source'));

		copyToClipboard($source.val());
		showFloatingAlert('success', $page.attr('data-msg-copied'));
	});
}

// Revoke a token after confirmation.
function mcpInitRevoke($page)
{
	$page.on('click', '.mcp-token-revoke', function() {
		var $button = $(this);
		var tokenId = $button.attr('data-token-id');

		showModalConfirm($page.attr('data-msg-confirm-revoke'), 'mcp-confirm-revoke', {
			on_show: function(modal) {
				modal.children().find('.mcp-confirm-revoke:first').click(function() {
					modal.modal('hide');
					$button.button('loading');

					fsAjax(
						{
							action: 'revoke',
							token_id: tokenId
						},
						laroute.route('mcpforfreescout.tokens.ajax'),
						function(response) {
							if (!isAjaxSuccess(response)) {
								$button.button('reset');
								showAjaxError(response);
								loaderHide();
								return;
							}
							$button.closest('tr').remove();
							mcpToggleEmpty($page);
							showAjaxResult(response);
						}, true,
						function() {
							$button.button('reset');
							showFloatingAlert('error', Lang.get('messages.ajax_error'));
							ajaxFinish();
						}
					);
				});
			}
		}, $page.attr('data-msg-revoke'));
	});
}

// Show the empty message instead of an empty table.
function mcpToggleEmpty($page)
{
	var empty = !$page.find('.mcp-tokens-table tbody tr').length;

	$page.find('.mcp-tokens-table').toggleClass('hidden', empty);
	$page.find('.mcp-tokens-empty').toggleClass('hidden', !empty);
}

/**
 * MCP for FreeScout: pre-select what an agent chose for a draft.
 *
 * The server embeds the choices of the conversation's drafts as JSON in
 * #mcpff-draft-choices (thread id => {signature_id, status, assignee_id}).
 */
var mcpffDraftChoices = {
	choices: {},

	// Apply the choices of a draft to the reply editor. Called once each time the
	// editor is opened for the draft, so the human's later changes are kept.
	apply: function(threadId) {
		var choices = this.choices[threadId];
		if (!choices) {
			return;
		}
		var $form = $('form.form-reply:first');

		// The Custom Signatures select swaps the signature preview on "change".
		this.select($form.find('select[name="cs_signature"]'), choices.signature_id, true);
		this.select($form.find('select[name="status"]'), choices.status, false);
		// "Unassign" is the "Anyone" option, whose value is -1.
		this.select($form.find('select[name="user_id"]'), choices.assignee_id === 0 ? -1 : choices.assignee_id, false);
	},

	// Select an option, if the select offers it.
	select: function($select, value, notify) {
		if (typeof value === 'undefined' || !$select.length) {
			return;
		}
		var offered = $select.find('option').filter(function() {
			return this.value === String(value);
		}).length;
		if (!offered) {
			return;
		}
		$select.val(String(value));
		if (notify) {
			$select.trigger('change');
		}
	}
};

// Entry point, called from the page footer on conversation pages.
function mcpffInitDraftChoices()
{
	$(document).ready(function() {
		var $data = $('#mcpff-draft-choices');
		if (!$data.length) {
			return;
		}
		try {
			mcpffDraftChoices.choices = JSON.parse($data.attr('data-choices')) || {};
		} catch (e) {
			return;
		}

		// FreeScout fills the reply editor from a draft with showReplyForm(data).
		var showReplyForm = window.showReplyForm;
		window.showReplyForm = function(data) {
			var result = showReplyForm.apply(this, arguments);
			if (data && data.thread_id) {
				mcpffDraftChoices.apply(data.thread_id);
			}
			return result;
		};

		// The draft of a new conversation is already in the create form. Wait
		// until the other modules have bound their handlers to the editor.
		var $create = $('#form-create');
		if ($create.length) {
			setTimeout(function() {
				mcpffDraftChoices.apply($create.find(':input[name="thread_id"]').val());
			}, 0);
		}
	});
}

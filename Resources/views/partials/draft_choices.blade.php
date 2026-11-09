{{-- What an agent chose for its drafts; module.js applies it when a draft is opened in the reply editor. --}}
@php $draftChoices = \Modules\McpForFreescout\Support\DraftChoices::forConversation($conversation); @endphp
@if ($draftChoices)
<div id="mcpff-draft-choices" class="hidden" data-choices="{{ json_encode($draftChoices, JSON_UNESCAPED_UNICODE) }}"></div>
@endif

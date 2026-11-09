@extends('layouts.app')

@section('title', __('MCP tokens'))

@section('content')
<div class="container mcp-tokens" data-msg-confirm-revoke="{{ __('Revoke this token? Any client using it will lose access immediately.') }}" data-msg-revoke="{{ __('Revoke') }}">
    <div class="flexy-container">
        <div class="flexy-item">
            <span class="heading">{{ __('MCP tokens') }}@if (count($tokens)) <small>({{ count($tokens) }})</small>@endif</span>
        </div>
    </div>

    <p class="text-help margin-top">{{ __('Every token acts as the user it belongs to. You can revoke any token, but only its owner can create one.') }}</p>

    <div class="mcp-tokens-list margin-top">
        @include('mcpforfreescout::tokens/partials/table')
    </div>
</div>
@endsection

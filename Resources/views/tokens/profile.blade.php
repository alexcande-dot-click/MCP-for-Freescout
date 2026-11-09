@extends('layouts.app')

@section('title_full', __('MCP tokens').' - '.$user->getFullName())

@section('body_attrs')@parent data-user_id="{{ $user->id }}"@endsection

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('users/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        {{ __('MCP tokens') }}
    </div>

    <div class="container form-container mcp-tokens"
        data-user-id="{{ $user->id }}"
        data-endpoint="{{ $endpoint }}"
        data-msg-confirm-revoke="{{ __('Revoke this token? Any client using it will lose access immediately.') }}"
        data-msg-revoke="{{ __('Revoke') }}"
        data-msg-copied="{{ __('Copied to clipboard') }}">
        <div class="row">
            <div class="col-xs-12">
                <p class="text-help margin-top">
                    {{ __('Tokens let an AI agent use FreeScout through MCP as this user. Treat a token like a password: anyone who holds it can do whatever this user can do.') }}
                </p>

                @if ($can_create)
                    <form class="form-inline margin-top mcp-token-form">
                        <div class="form-group">
                            <label for="mcp-token-label" class="sr-only">{{ __('Label') }}</label>
                            <input id="mcp-token-label" type="text" class="form-control input-sized" maxlength="100" placeholder="{{ __('Label, e.g. OpenClaw') }}" required>
                        </div>
                        <button type="submit" class="btn btn-primary" data-loading-text="{{ __('Creating') }}…">{{ __('Create token') }}</button>
                    </form>

                    <div class="mcp-token-created alert alert-warning margin-top hidden">
                        <p><strong><i class="glyphicon glyphicon-exclamation-sign"></i> {{ __('Copy your new token now. It will not be shown again.') }}</strong></p>

                        <div class="input-group">
                            <input type="text" class="form-control mcp-token-plaintext" readonly aria-label="{{ __('New token') }}">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default mcp-copy" data-copy-source=".mcp-token-plaintext"><i class="glyphicon glyphicon-duplicate"></i> {{ __('Copy') }}</button>
                            </span>
                        </div>

                        <p class="margin-top-10">{{ __('To connect OpenClaw, run:') }}</p>
                        <div class="input-group">
                            <textarea class="form-control mcp-token-snippet" rows="3" readonly aria-label="{{ __('OpenClaw configuration command') }}"></textarea>
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default mcp-copy" data-copy-source=".mcp-token-snippet"><i class="glyphicon glyphicon-duplicate"></i> {{ __('Copy') }}</button>
                            </span>
                        </div>

                        <p class="margin-top-10 margin-bottom-0"><button type="button" class="btn btn-link mcp-token-dismiss">{{ __('I have saved the token') }}</button></p>
                    </div>
                @else
                    <div class="alert alert-info margin-top">
                        {{ __('Only :name can create tokens for this account. As an administrator you can list and revoke them.', ['name' => $user->getFullName()]) }}
                    </div>
                @endif

                <div class="mcp-tokens-list margin-top">
                    @include('mcpforfreescout::tokens/partials/table')
                </div>
            </div>
        </div>
    </div>
@endsection

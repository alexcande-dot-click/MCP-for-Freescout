{{-- Variables: $tokens (collection of McpToken), $show_owner (bool). --}}
<p class="text-help mcp-tokens-empty @if (count($tokens)) hidden @endif">{{ __('No tokens yet.') }}</p>

<table class="table mcp-tokens-table @if (!count($tokens)) hidden @endif">
    <thead>
        <tr>
            @if ($show_owner)
                <th>{{ __('User') }}</th>
            @endif
            <th>{{ __('Label') }}</th>
            <th>{{ __('Token') }}</th>
            <th>{{ __('Created') }}</th>
            <th>{{ __('Last used') }}</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($tokens as $token)
            <tr data-token-id="{{ $token->id }}">
                @if ($show_owner)
                    <td>
                        @if ($token->user)
                            <a href="{{ route('mcpforfreescout.tokens.profile', ['id' => $token->user_id]) }}">{{ $token->user->getFullName() }}</a>
                        @endif
                    </td>
                @endif
                <td>{{ $token->label }}</td>
                <td><code>{{ $token->token_prefix }}…</code></td>
                <td>{{ \App\User::dateFormat($token->created_at) }}</td>
                <td>
                    @if ($token->last_used_at)
                        <span @if ($token->last_used_ip) title="{{ $token->last_used_ip }}" @endif>{{ \App\User::dateFormat($token->last_used_at) }}</span>
                    @else
                        <span class="text-help">{{ __('Never') }}</span>
                    @endif
                </td>
                <td class="text-right">
                    <button type="button" class="btn btn-default btn-xs mcp-token-revoke" data-token-id="{{ $token->id }}" data-loading-text="{{ __('Revoking') }}…"><i class="glyphicon glyphicon-trash"></i> {{ __('Revoke') }}</button>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

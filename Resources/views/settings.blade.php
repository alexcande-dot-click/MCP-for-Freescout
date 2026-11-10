<form class="form-horizontal margin-top" method="POST" action="">
    {{ csrf_field() }}

    <div class="form-group">
        <label for="mcpforfreescout-enabled" class="col-sm-2 control-label">{{ __('Enable MCP') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[mcpforfreescout.enabled]" value="1" id="mcpforfreescout-enabled" class="onoffswitch-checkbox" @if (old('settings[mcpforfreescout.enabled]', $settings['mcpforfreescout.enabled']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="mcpforfreescout-enabled"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">
                {{ __('When disabled, the MCP endpoint answers every request with 503 and no client can connect.') }}
            </p>
        </div>
    </div>

    <div class="form-group">
        <label for="mcpforfreescout-endpoint" class="col-sm-2 control-label">{{ __('Endpoint URL') }}</label>

        <div class="col-sm-6">
            <input id="mcpforfreescout-endpoint" type="text" class="form-control input-sized-lg" value="{{ route('mcpforfreescout.endpoint') }}" readonly>
            <p class="help-block">
                {{ __('Give this URL and a personal token to your MCP client. Tokens are created on each user\'s profile.') }}
            </p>
        </div>
    </div>

    <div class="form-group">
        <label for="mcpforfreescout-max-attachment-size" class="col-sm-2 control-label">{{ __('Maximum attachment size (MB)') }}</label>

        <div class="col-sm-6">
            <input id="mcpforfreescout-max-attachment-size" type="number" min="1" step="any" name="settings[mcpforfreescout.max_attachment_size]" class="form-control input-sized" value="{{ round($settings['mcpforfreescout.max_attachment_size'] / 1048576, 2) }}">
            <p class="help-block">
                {{ __('Largest file an AI agent can add to a draft or a note, or receive as content. The minimum is 1 MB.') }}
            </p>
        </div>
    </div>

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save') }}
            </button>
        </div>
    </div>
</form>

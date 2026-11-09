<?php

Route::group([
    'middleware' => 'open',
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\McpForFreescout\Http\Controllers',
], function () {
    // Authenticated by bearer token, not by session: no CSRF check, no cookies.
    Route::match(['GET', 'POST', 'DELETE'], '/mcp', 'McpController@handle')->name('mcpforfreescout.endpoint');
});

Route::group([
    'middleware' => 'web',
    'prefix'     => \Helper::getSubdirectory(),
    'namespace'  => 'Modules\McpForFreescout\Http\Controllers',
], function () {
    Route::get('/users/{id}/mcp-tokens', ['uses' => 'TokensController@profile', 'middleware' => 'auth'])
        ->where('id', '[0-9]+')
        ->name('mcpforfreescout.tokens.profile');
    Route::get('/mcp-tokens', ['uses' => 'TokensController@admin', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])
        ->name('mcpforfreescout.tokens.admin');
    Route::post('/mcp-tokens/ajax', ['uses' => 'TokensController@ajax', 'middleware' => 'auth', 'laroute' => true])
        ->name('mcpforfreescout.tokens.ajax');
});

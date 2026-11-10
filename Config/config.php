<?php

return [
    'name' => 'McpForFreescout',

    // Defaults for Option::get('mcpforfreescout.<key>').
    'options' => [
        'enabled' => [
            'default' => true,
        ],
        // Largest file, in bytes, that get_attachment returns as content and that
        // can be attached to a draft.
        'max_attachment_size' => [
            'default' => 10485760,
        ],
    ],
];

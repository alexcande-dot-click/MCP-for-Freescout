<?php

namespace Modules\McpForFreescout\Support;

/**
 * Detects the optional companion modules this module integrates with.
 */
final class CompanionModules
{
    const CUSTOM_SIGNATURES = 'customsignatures';
    const SAVED_REPLIES = 'savedreplies';
    const TAGS = 'tags';
    const SEND_LATER = 'sendlater';

    /**
     * Whether a companion module is installed and active.
     *
     * The `mcpforfreescout.companion_active` filter lets tests and integrators
     * switch an integration on or off regardless of the module state.
     *
     * @param string $alias One of the class constants.
     */
    public static function isActive(string $alias): bool
    {
        return (bool) \Eventy::filter('mcpforfreescout.companion_active', \Module::isActive($alias), $alias);
    }
}

<?php

namespace Modules\McpForFreescout\Support;

use App\Mailbox;

/**
 * Prepares text written by a model for FreeScout's HTML editor fields.
 */
final class BodyFormatter
{
    /**
     * Convert a message body to HTML.
     *
     * A body that contains HTML tags is returned unchanged, because FreeScout
     * sanitises it when the thread is saved. Anything else is treated as plain
     * text: special characters are escaped and line breaks become `<br>`.
     */
    public static function toHtml(string $body): string
    {
        if (strip_tags($body) !== $body) {
            return $body;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $body);

        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }

    /**
     * Convert a text to HTML and purify it with the purifier core applies to the mailbox
     * signature, which keeps FreeScout variables such as `{%user.firstName%}` intact.
     *
     * @return string The purified HTML; empty when nothing is left of the text.
     */
    public static function purifiedHtml(string $text): string
    {
        return trim(Mailbox::sanitizeSignature(self::toHtml(trim($text))));
    }
}

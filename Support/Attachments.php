<?php

namespace Modules\McpForFreescout\Support;

use App\Attachment;
use App\Option;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Presents FreeScout attachments as plain arrays for tool results.
 */
final class Attachments
{
    /** Used when the option is unset and a cached config predates its default. */
    const DEFAULT_MAX_SIZE = 10485760;

    /** The smallest limit the settings page accepts: 1 MB. */
    const MIN_MAX_SIZE = 1048576;

    /**
     * One file: its metadata and the signed download link of the web app.
     */
    public static function present(Attachment $attachment): array
    {
        return [
            'id'        => (int) $attachment->id,
            'file_name' => self::text($attachment->file_name),
            'mime_type' => self::text($attachment->mime_type),
            'size'      => $attachment->size !== null ? (int) $attachment->size : null,
            'embedded'  => (bool) $attachment->embedded,
            'url'       => $attachment->url(),
        ];
    }

    /**
     * All files of a thread: the regular attachments first, then the embedded
     * images, each group ordered by id.
     */
    public static function ofThread(Thread $thread): array
    {
        $files = $thread->attachments->sortBy('id')->values()
            ->concat($thread->embeds->sortBy('id')->values());

        return $files->map(function (Attachment $attachment) {
            return self::present($attachment);
        })->all();
    }

    /**
     * Find a file the user may see: it belongs to a thread of a conversation the user
     * can view, and the thread is visible to the user (see Lookup::threadVisible()).
     * A file without a thread (a saved-reply file or an orphan upload) is not found.
     *
     * @throws ToolError When the file does not exist or is not accessible.
     */
    public static function findVisible(int $id, User $user): Attachment
    {
        $attachment = Attachment::find($id);
        $thread = $attachment && $attachment->thread_id ? Thread::find($attachment->thread_id) : null;

        try {
            if (!$thread || !Lookup::threadVisible($thread, $user)) {
                throw new ToolError('Attachment not found or not accessible.');
            }
            Lookup::conversation((int) $thread->conversation_id, $user);
        } catch (ToolError $e) {
            // A hidden conversation looks the same as a missing file.
            throw new ToolError('Attachment not found or not accessible.');
        }

        return $attachment;
    }

    /**
     * The largest file, in bytes, that is returned as content or attached to a draft.
     */
    public static function maxSize(): int
    {
        $size = (int) Option::get('mcpforfreescout.max_attachment_size');

        return $size > 0 ? $size : self::DEFAULT_MAX_SIZE;
    }

    /**
     * Convert the megabytes typed on the settings page into the stored limit in bytes.
     *
     * Values below 1 MB are raised to 1 MB; an empty or non-numeric value gives the default.
     *
     * @param mixed $megabytes
     */
    public static function bytesFromMegabytes($megabytes): int
    {
        if (!is_numeric($megabytes)) {
            return self::DEFAULT_MAX_SIZE;
        }

        return max(self::MIN_MAX_SIZE, (int) round((float) $megabytes * 1048576));
    }

    /**
     * The bytes of a file, read from the storage disk of the core.
     *
     * @throws ToolError When the stored file is gone.
     */
    public static function contents(Attachment $attachment): string
    {
        $disk = Attachment::getDisk();
        $path = $attachment->getStorageFilePath();

        $bytes = $attachment->file_dir !== null && $disk->exists($path) ? $disk->get($path) : null;
        if (!is_string($bytes)) {
            throw new ToolError('The stored file of attachment '.$attachment->id.' is missing.');
        }

        return $bytes;
    }

    /**
     * Normalise a file name or MIME type to valid UTF-8, as the Presenter does.
     *
     * @param mixed $value
     */
    private static function text($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8');
    }
}

<?php

namespace Modules\McpForFreescout\Support;

use App\Attachment;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;

/**
 * Turns the `attachments` argument of a tool into stored FreeScout files.
 *
 * Each item takes its content from exactly one source: a link to download, base64
 * content, or an existing attachment the user can see. The files are stored without a
 * thread, ready to be attached to a draft.
 */
final class FileInputs
{
    const SOURCES = ['url', 'content_base64', 'attachment_id'];

    /** Most files in one call: each link may take up to a download timeout. */
    const MAX_FILES = 20;

    const FIELDS = ['file_name', 'url', 'content_base64', 'attachment_id', 'mime_type'];

    /**
     * The JSON schema of the `attachments` argument.
     */
    public static function schema(string $description): array
    {
        return [
            'type'        => 'array',
            'description' => $description,
            'maxItems'    => self::MAX_FILES,
            'items'       => [
                'type'                 => 'object',
                'description'          => 'One file. Give exactly one of url, content_base64 or attachment_id.',
                'properties'           => [
                    'file_name'      => ['type' => 'string', 'description' => 'File name with its extension, for example "invoice.pdf". Required unless attachment_id is given; then it defaults to the original name.'],
                    'url'            => ['type' => 'string', 'description' => 'Public http or https link to download the file from. Redirects are not followed: give the final link.'],
                    'content_base64' => ['type' => 'string', 'description' => 'The file content in base64. A "data:<type>;base64," prefix and line breaks are accepted.'],
                    'attachment_id'  => ['type' => 'integer', 'description' => 'Copy an existing file, by the id that get_conversation lists.'],
                    'mime_type'      => ['type' => 'string', 'description' => 'MIME type, for example "application/pdf". Detected from the content when omitted.'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Validate every item, then store the files. All or nothing: when one item fails,
     * the files already stored are deleted.
     *
     * @param array $items The `attachments` argument.
     *
     * @return Attachment[] The stored files, in the order of the items.
     *
     * @throws ToolError Naming the failing item, as in "attachments[1]: ...", or refusing more than MAX_FILES items.
     */
    public static function create(array $items, User $user): array
    {
        if (count($items) > self::MAX_FILES) {
            throw new ToolError('attachments: at most '.self::MAX_FILES.' files per call');
        }

        // Check every item before downloading or storing anything.
        $plans = [];
        foreach (array_values($items) as $index => $item) {
            $plans[] = self::forItem($index, function () use ($item, $user) {
                return self::plan($item, $user);
            });
        }

        $created = [];
        try {
            foreach ($plans as $index => $plan) {
                $created[] = self::forItem($index, function () use ($plan, $user) {
                    return self::store($plan, $user);
                });
            }
        } catch (\Throwable $e) {
            if ($created) {
                Attachment::deleteForever(collect($created));
            }

            throw $e;
        }

        return $created;
    }

    /**
     * The combined size of stored files, in bytes.
     *
     * @param Attachment[] $attachments
     */
    public static function totalSize(array $attachments): int
    {
        $total = 0;
        foreach ($attachments as $attachment) {
            $total += (int) $attachment->size;
        }

        return $total;
    }

    /**
     * Run a step for one item, prefixing its tool errors with the item's position.
     */
    private static function forItem(int $index, callable $step)
    {
        try {
            return $step();
        } catch (ToolError $e) {
            throw new ToolError('attachments['.$index.']: '.$e->getMessage());
        }
    }

    /**
     * Check one item without network or disk access.
     *
     * @param mixed $item
     *
     * @return array The checked item: source, file_name, mime_type and its content
     *               (bytes, url or the visible Attachment).
     */
    private static function plan($item, User $user): array
    {
        if (!is_array($item) || ($item && array_keys($item) === range(0, count($item) - 1))) {
            throw new ToolError('Each file must be an object.');
        }
        foreach (array_keys($item) as $key) {
            if (!in_array($key, self::FIELDS, true)) {
                throw new ToolError('Unknown field "'.$key.'".');
            }
        }

        $sources = array_values(array_filter(self::SOURCES, function ($key) use ($item) {
            return isset($item[$key]);
        }));
        if (count($sources) !== 1) {
            throw new ToolError('Give exactly one of url, content_base64 or attachment_id.');
        }
        $source = $sources[0];

        $fileName = self::optionalString($item, 'file_name');
        if ($fileName !== null) {
            $fileName = self::safeFileName($fileName);
        } elseif ($source !== 'attachment_id') {
            throw new ToolError('"file_name" is required.');
        }

        $mimeType = self::optionalString($item, 'mime_type');
        if ($mimeType !== null) {
            $mimeType = self::mimeType($mimeType);
            if ($mimeType === null) {
                throw new ToolError('"mime_type" is not a valid MIME type.');
            }
        }

        $plan = ['source' => $source, 'file_name' => $fileName, 'mime_type' => $mimeType];

        switch ($source) {
            case 'content_base64':
                [$plan['bytes'], $dataMimeType] = self::decodeBase64($item['content_base64']);
                $plan['mime_type'] = $plan['mime_type'] ?? $dataMimeType;
                self::assertSize(strlen($plan['bytes']));
                break;

            case 'url':
                $plan['url'] = self::optionalString($item, 'url');
                $scheme = $plan['url'] !== null ? strtolower((string) parse_url($plan['url'], PHP_URL_SCHEME)) : '';
                if (!in_array($scheme, ['http', 'https'], true) || !parse_url($plan['url'], PHP_URL_HOST)) {
                    throw new ToolError('"url" must be an http or https link.');
                }
                break;

            case 'attachment_id':
                $id = $item['attachment_id'];
                if (is_string($id) && preg_match('/^\d+$/', $id)) {
                    $id = (int) $id;
                }
                if (!is_int($id)) {
                    throw new ToolError('"attachment_id" must be an integer.');
                }
                $plan['attachment'] = Attachments::findVisible($id, $user);
                if ($plan['attachment']->size !== null) {
                    self::assertSize((int) $plan['attachment']->size);
                }
                break;
        }

        return $plan;
    }

    /**
     * Download or copy the content of a checked item and store it.
     */
    private static function store(array $plan, User $user): Attachment
    {
        if ($plan['source'] === 'attachment_id') {
            $original = $plan['attachment'];
            $sameName = $plan['file_name'] === null || $plan['file_name'] === $original->file_name;
            if ($sameName && method_exists($original, 'duplicate')) {
                return self::duplicate($original, $plan['mime_type'], $user);
            }

            return self::save(
                $plan['file_name'] ?? $original->file_name,
                $plan['mime_type'] ?? $original->mime_type,
                Attachments::contents($original),
                $user
            );
        }

        $bytes = $plan['bytes'] ?? null;
        $mimeType = $plan['mime_type'];
        if ($plan['source'] === 'url') {
            $download = app(RemoteFetcher::class)->fetch($plan['url'], Attachments::maxSize());
            $bytes = (string) ($download['bytes'] ?? '');
            $mimeType = $mimeType ?? self::mimeType((string) ($download['mime_type'] ?? ''));
            self::assertSize(strlen($bytes));
        }

        return self::save($plan['file_name'], $mimeType, $bytes, $user);
    }

    /**
     * Store bytes as a new file of the user. Core sanitizes the name and the content.
     */
    private static function save(string $fileName, ?string $mimeType, string $bytes, User $user): Attachment
    {
        if ($bytes === '') {
            throw new ToolError('The file is empty.');
        }
        if ($mimeType === null) {
            $mimeType = self::detectMimeType($bytes);
        }

        $attachment = Attachment::create($fileName, $mimeType, null, $bytes, null, false, null, $user->id);
        if (!$attachment) {
            throw new ToolError('The file could not be stored.');
        }
        if (!$attachment->file_dir || !$attachment->fileExists()) {
            Attachment::deleteForever(collect([$attachment]));

            throw new ToolError('The file could not be stored.');
        }

        return $attachment;
    }

    /**
     * Copy an existing file with core's duplicate(), as a file of the user.
     */
    private static function duplicate(Attachment $original, ?string $mimeType, User $user): Attachment
    {
        $copy = $original->duplicate(null);
        if (!$copy) {
            throw new ToolError('The stored file of attachment '.$original->id.' is missing.');
        }

        if ($copy->file_dir === $original->file_dir || !$copy->fileExists()) {
            // The copy shares the original's path or has no file: remove the row only,
            // never the file, which may be the original's.
            Attachment::where('id', $copy->id)->delete();

            throw new ToolError('The file of attachment '.$original->id.' could not be copied.');
        }

        $copy->user_id = $user->id;
        $copy->embedded = false;
        if ($mimeType !== null) {
            $copy->mime_type = $mimeType;
        }
        $copy->size = Attachment::getDisk()->size($copy->getStorageFilePath());
        $copy->save();

        try {
            self::assertSize((int) $copy->size);
        } catch (ToolError $e) {
            Attachment::deleteForever(collect([$copy]));

            throw $e;
        }

        return $copy;
    }

    /**
     * Decode base64 content, accepting a data URI prefix and whitespace.
     *
     * @param mixed $value
     *
     * @return array [string $bytes, ?string $mimeType] The MIME type of a data URI.
     */
    private static function decodeBase64($value): array
    {
        if (!is_string($value)) {
            throw new ToolError('"content_base64" must be a string.');
        }

        $mimeType = null;
        if (preg_match('/^\s*data:([^,;]*)((?:;[^,;]*)*?);base64,/i', $value, $m)) {
            $mimeType = self::mimeType($m[1]);
            $value = substr($value, strlen($m[0]));
        }
        // Line breaks are common in encoded files; the URL-safe alphabet is accepted too.
        $value = strtr(preg_replace('/\s+/', '', $value), '-_', '+/');

        $bytes = $value === '' ? false : base64_decode($value, true);
        if ($bytes === false) {
            throw new ToolError('"content_base64" is not valid base64.');
        }

        return [$bytes, $mimeType];
    }

    /**
     * Reduce a file name to its last path segment, so it never names a directory.
     * Core then replaces unsafe characters and renames dangerous extensions.
     */
    private static function safeFileName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = substr($name, strrpos($name, '/') === false ? 0 : strrpos($name, '/') + 1);
        $name = preg_replace('/\.{2,}/', '.', $name);
        $name = ltrim(trim($name), '.');

        if ($name === '') {
            throw new ToolError('"file_name" is not a valid file name.');
        }

        return $name;
    }

    /**
     * Normalise a MIME type such as "Text/Plain; charset=UTF-8" to "text/plain".
     *
     * @return string|null Null when the value is not a MIME type.
     */
    private static function mimeType(string $value): ?string
    {
        $type = strtolower(trim(explode(';', $value)[0]));

        return preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $type) ? $type : null;
    }

    private static function detectMimeType(string $bytes): string
    {
        $detected = class_exists(\finfo::class) ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : false;

        return is_string($detected) && self::mimeType($detected) !== null
            ? self::mimeType($detected)
            : 'application/octet-stream';
    }

    private static function assertSize(int $size): void
    {
        $max = Attachments::maxSize();
        if ($size > $max) {
            throw new ToolError('The file is larger than '.$max.' bytes.');
        }
    }

    /**
     * Read an optional string field; an empty string counts as absent.
     *
     * @return string|null
     */
    private static function optionalString(array $item, string $key): ?string
    {
        if (!isset($item[$key])) {
            return null;
        }
        if (!is_string($item[$key])) {
            throw new ToolError('"'.$key.'" must be a string.');
        }

        $value = trim($item[$key]);

        return $value === '' ? null : $value;
    }
}

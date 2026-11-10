<?php

namespace Modules\McpForFreescout\Tests\Integration;

use App\Attachment;
use App\Option;
use App\Thread;
use App\User;
use Modules\McpForFreescout\Mcp\ToolError;
use Modules\McpForFreescout\Support\Attachments;
use Modules\McpForFreescout\Support\FileInputs;
use Modules\McpForFreescout\Support\RemoteFetcher;
use Modules\McpForFreescout\Tests\Fixtures;
use Modules\McpForFreescout\Tests\TestCase;

class FileInputsTest extends TestCase
{
    const LIMIT_OPTION = 'mcpforfreescout.max_attachment_size';

    /** @var int The highest attachment id before the test. */
    private $lastId;

    /** @var FakeRemoteFetcher */
    private $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lastId = (int) Attachment::max('id');
        $this->fetcher = new FakeRemoteFetcher();
        app()->instance(RemoteFetcher::class, $this->fetcher);
    }

    protected function tearDown(): void
    {
        // The database rollback does not remove the stored files.
        $created = Attachment::where('id', '>', $this->lastId)->get();
        if (count($created)) {
            Attachment::deleteForever($created);
        }
        app()->forgetInstance(RemoteFetcher::class);
        Option::remove(self::LIMIT_OPTION);
        unset(Option::$cache[self::LIMIT_OPTION]);

        parent::tearDown();
    }

    public function test_base64_with_data_uri_prefix_and_newlines_is_accepted()
    {
        $user = Fixtures::user();
        $bytes = "Hello from the agent.\n".random_bytes(48);
        $encoded = 'data:text/plain;base64,'.chunk_split(base64_encode($bytes), 16, "\r\n");

        $files = FileInputs::create([['file_name' => 'note.txt', 'content_base64' => $encoded]], $user);

        $this->assertCount(1, $files);
        $file = $files[0];
        $this->assertInstanceOf(Attachment::class, $file);
        $this->assertSame('note.txt', $file->file_name);
        $this->assertSame('text/plain', $file->mime_type);
        $this->assertSame($bytes, Attachments::contents($file));
        $this->assertNull($file->thread_id);
        $this->assertFalse((bool) $file->embedded);
        $this->assertSame((int) $user->id, (int) $file->user_id);
        $this->assertSame(strlen($bytes), (int) $file->size);
    }

    public function test_mime_type_is_detected_when_not_given()
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $files = FileInputs::create([['file_name' => 'pixel.png', 'content_base64' => base64_encode($png)]], Fixtures::user());

        $this->assertSame('image/png', $files[0]->mime_type);
    }

    public function test_invalid_base64_names_the_item()
    {
        $items = [
            ['file_name' => 'good.txt', 'content_base64' => base64_encode('fine')],
            ['file_name' => 'bad.txt', 'content_base64' => 'not*valid*base64!'],
        ];

        $message = $this->failure($items, Fixtures::user());

        $this->assertStringStartsWith('attachments[1]:', $message);
        $this->assertSame(0, $this->createdCount());
    }

    public function test_url_source_uses_the_fetcher()
    {
        $bytes = "%PDF-1.4\n".random_bytes(32);
        $this->fetcher->respond(['bytes' => $bytes, 'mime_type' => 'application/pdf']);

        $files = FileInputs::create([['file_name' => 'invoice.pdf', 'url' => 'https://files.example.com/invoice.pdf']], Fixtures::user());

        $this->assertSame([['https://files.example.com/invoice.pdf', Attachments::maxSize()]], $this->fetcher->calls);
        $this->assertSame('invoice.pdf', $files[0]->file_name);
        $this->assertSame('application/pdf', $files[0]->mime_type);
        $this->assertSame($bytes, Attachments::contents($files[0]));
    }

    public function test_existing_visible_attachment_is_copied()
    {
        [$user, $thread] = $this->scenario();
        $original = Attachment::create('photo.png', 'image/png', null, 'png bytes', null, true, $thread->id);

        $files = FileInputs::create([['attachment_id' => $original->id]], $user);

        $copy = $files[0];
        $this->assertNotSame((int) $original->id, (int) $copy->id);
        $this->assertSame('photo.png', $copy->file_name);
        $this->assertSame('image/png', $copy->mime_type);
        $this->assertNull($copy->thread_id);
        $this->assertFalse((bool) $copy->embedded);
        $this->assertSame((int) $user->id, (int) $copy->user_id);
        $this->assertNotSame($original->getStorageFilePath(), $copy->getStorageFilePath());
        $this->assertSame('png bytes', Attachments::contents($copy));
        $this->assertSame('png bytes', Attachments::contents($original->fresh()));
    }

    public function test_existing_attachment_can_be_renamed()
    {
        [$user, $thread] = $this->scenario();
        $original = Attachment::create('scan.pdf', 'application/pdf', null, 'pdf bytes', null, false, $thread->id);

        $files = FileInputs::create([['attachment_id' => $original->id, 'file_name' => 'contract.pdf']], $user);

        $this->assertSame('contract.pdf', $files[0]->file_name);
        $this->assertSame('application/pdf', $files[0]->mime_type);
        $this->assertSame('pdf bytes', Attachments::contents($files[0]));
    }

    public function test_invisible_attachment_is_not_found()
    {
        [, $thread] = $this->scenario();
        $original = Attachment::create('secret.pdf', 'application/pdf', null, 'secret', null, false, $thread->id);
        $before = $this->createdCount();

        $message = $this->failure([['attachment_id' => $original->id]], Fixtures::user());

        $this->assertSame('attachments[0]: Attachment not found or not accessible.', $message);
        $this->assertSame($before, $this->createdCount());
    }

    public function test_more_than_one_source_is_refused()
    {
        $user = Fixtures::user();

        $two = $this->failure([['file_name' => 'a.txt', 'url' => 'https://example.com/a.txt', 'content_base64' => base64_encode('a')]], $user);
        $none = $this->failure([['file_name' => 'a.txt'], ['file_name' => 'b.txt']], $user);

        $this->assertStringStartsWith('attachments[0]: ', $two);
        $this->assertStringContainsString('exactly one of url, content_base64 or attachment_id', $two);
        $this->assertStringStartsWith('attachments[0]: ', $none);
        $this->assertSame([], $this->fetcher->calls);
        $this->assertSame(0, $this->createdCount());
    }

    public function test_invalid_link_is_refused_before_any_download()
    {
        $items = [
            ['file_name' => 'a.txt', 'url' => 'https://example.com/a.txt'],
            ['file_name' => 'b.txt', 'url' => 'file:///etc/passwd'],
        ];

        $message = $this->failure($items, Fixtures::user());

        $this->assertSame('attachments[1]: "url" must be an http or https link.', $message);
        $this->assertSame([], $this->fetcher->calls);
    }

    public function test_file_name_is_required_without_attachment_id()
    {
        $message = $this->failure([['content_base64' => base64_encode('a')]], Fixtures::user());

        $this->assertSame('attachments[0]: "file_name" is required.', $message);
    }

    public function test_unknown_fields_are_refused()
    {
        $message = $this->failure([['file_name' => 'a.txt', 'content_base64' => base64_encode('a'), 'path' => '/etc/passwd']], Fixtures::user());

        $this->assertSame('attachments[0]: Unknown field "path".', $message);
    }

    public function test_oversized_file_is_refused()
    {
        Option::set(self::LIMIT_OPTION, 10);
        unset(Option::$cache[self::LIMIT_OPTION]);
        $user = Fixtures::user();

        $inline = $this->failure([['file_name' => 'big.txt', 'content_base64' => base64_encode('eleven byte')]], $user);
        $this->fetcher->respond(['bytes' => 'eleven byte', 'mime_type' => null]);
        $linked = $this->failure([['file_name' => 'ok.txt', 'content_base64' => base64_encode('ten bytes!')], ['file_name' => 'big.txt', 'url' => 'https://example.com/big.txt']], $user);

        $this->assertSame('attachments[0]: The file is larger than 10 bytes.', $inline);
        $this->assertSame('attachments[1]: The file is larger than 10 bytes.', $linked);
        $this->assertSame(10, $this->fetcher->calls[0][1]);
        $this->assertSame(0, $this->createdCount());
    }

    public function test_at_most_twenty_files_per_call()
    {
        $this->assertSame(20, FileInputs::MAX_FILES);
        $this->assertSame(20, FileInputs::schema('Files.')['maxItems']);

        $item = ['file_name' => 'a.txt', 'content_base64' => base64_encode('a')];
        $message = $this->failure(array_fill(0, 21, $item), Fixtures::user());

        $this->assertSame('attachments: at most 20 files per call', $message);
        $this->assertSame(0, $this->createdCount());
        $this->assertCount(20, FileInputs::create(array_fill(0, 20, $item), Fixtures::user()));
    }

    public function test_failure_rolls_back_created_files()
    {
        $user = Fixtures::user();
        $before = Attachment::count();
        $storedBeforeFailure = [];
        $lastId = $this->lastId;
        $this->fetcher->respond(function () use (&$storedBeforeFailure, $lastId) {
            foreach (Attachment::where('id', '>', $lastId)->get() as $attachment) {
                $storedBeforeFailure[] = $attachment->getStorageFilePath();
            }

            throw new ToolError('The link answered with HTTP status 404.');
        });
        $items = [
            ['file_name' => 'first.txt', 'content_base64' => base64_encode('first file')],
            ['file_name' => 'second.txt', 'url' => 'https://example.com/missing.txt'],
        ];

        $message = $this->failure($items, $user);

        $this->assertSame('attachments[1]: The link answered with HTTP status 404.', $message);
        $this->assertCount(1, $storedBeforeFailure, 'The first file is created before the second one fails.');
        $this->assertSame($before, Attachment::count());
        $this->assertFalse(Attachment::getDisk()->exists($storedBeforeFailure[0]));
    }

    public function test_dangerous_file_name_is_sanitized()
    {
        $user = Fixtures::user();
        $items = [
            ['file_name' => '../../x.php', 'content_base64' => base64_encode('<?php echo 1;')],
            ['file_name' => 'a.phtml', 'content_base64' => base64_encode('<?php echo 2;')],
            ['file_name' => '..\\..\\evil.php5', 'content_base64' => base64_encode('<?php echo 3;')],
        ];

        $files = FileInputs::create($items, $user);

        $this->assertCount(3, $files);
        foreach ($files as $file) {
            $name = $file->file_name;
            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString('..', $name);
            $this->assertDoesNotMatchRegularExpression('/\.(php\d*|phtml)$/i', $name);
            $this->assertStringNotContainsString('..', $file->getStorageFilePath());
            $this->assertTrue(Attachment::getDisk()->exists($file->getStorageFilePath()));
        }
    }

    public function test_total_size_adds_up_file_sizes()
    {
        $files = FileInputs::create([
            ['file_name' => 'a.txt', 'content_base64' => base64_encode('12345')],
            ['file_name' => 'b.txt', 'content_base64' => base64_encode('123')],
        ], Fixtures::user());

        $this->assertSame(8, FileInputs::totalSize($files));
        $this->assertSame(0, FileInputs::totalSize([]));
    }

    public function test_schema_describes_the_items()
    {
        $schema = FileInputs::schema('Files to attach.');

        $this->assertSame('array', $schema['type']);
        $this->assertSame('Files to attach.', $schema['description']);
        $this->assertSame(['file_name', 'url', 'content_base64', 'attachment_id', 'mime_type'], array_keys($schema['items']['properties']));
        $this->assertFalse($schema['items']['additionalProperties']);
    }

    /**
     * Run FileInputs::create() expecting a ToolError, and return its message.
     */
    private function failure(array $items, User $user): string
    {
        try {
            FileInputs::create($items, $user);
        } catch (ToolError $e) {
            return $e->getMessage();
        }

        $this->fail('A ToolError was expected.');
    }

    private function createdCount(): int
    {
        return Attachment::where('id', '>', $this->lastId)->count();
    }

    /**
     * A user with access to a conversation, and its customer thread.
     *
     * @return array [User, Thread]
     */
    private function scenario(): array
    {
        $user = Fixtures::user();
        $mailbox = Fixtures::mailbox([], [$user]);
        $conversation = Fixtures::conversation($mailbox, Fixtures::customer());

        return [$user, Thread::where('conversation_id', $conversation->id)->first()];
    }
}

/**
 * Records the downloads asked for and answers with a fixed result or a callback.
 */
class FakeRemoteFetcher implements RemoteFetcher
{
    /** @var array[] [url, maxBytes] per call. */
    public $calls = [];

    /** @var array|callable */
    private $response = ['bytes' => 'downloaded', 'mime_type' => null];

    /**
     * @param array|callable $response
     */
    public function respond($response): void
    {
        $this->response = $response;
    }

    public function fetch(string $url, int $maxBytes): array
    {
        $this->calls[] = [$url, $maxBytes];

        return is_callable($this->response) ? call_user_func($this->response, $url, $maxBytes) : $this->response;
    }
}

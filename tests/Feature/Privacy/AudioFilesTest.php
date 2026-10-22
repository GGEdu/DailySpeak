<?php

namespace Tests\Feature\Privacy;

use App\Services\Privacy\AudioFiles;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudioFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/dailyspeak-audio-'.bin2hex(random_bytes(4));
        File::makeDirectory("{$this->root}/debates/1/recordings", 0755, true);
        file_put_contents("{$this->root}/debates/1/recordings/turn.webm", 'audio');
    }

    protected function tearDown(): void
    {
        // Give the directories their permissions back, or the temporary tree cannot be removed.
        @chmod("{$this->root}/debates/1", 0755);
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_a_file_that_is_gone_is_missing(): void
    {
        unlink("{$this->root}/debates/1/recordings/turn.webm");

        $this->assertSame(AudioFiles::MISSING, (new AudioFiles)->removeFile($this->disk(), 'debates/1/recordings/turn.webm'));
    }

    public function test_a_file_that_is_there_is_removed(): void
    {
        $this->assertSame(AudioFiles::REMOVED, (new AudioFiles)->removeFile($this->disk(), 'debates/1/recordings/turn.webm'));
        $this->assertFileDoesNotExist("{$this->root}/debates/1/recordings/turn.webm");
    }

    public function test_a_file_behind_a_directory_that_cannot_be_read_is_not_taken_for_missing(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads any directory, so the permission cannot be denied here.');
        }

        // EACCES on the way to the file makes exists() say false while the file is still on the disk.
        chmod("{$this->root}/debates/1", 0000);

        $this->assertSame(AudioFiles::FAILED, (new AudioFiles)->removeFile($this->disk(), 'debates/1/recordings/turn.webm'));

        chmod("{$this->root}/debates/1", 0755);
        $this->assertFileExists("{$this->root}/debates/1/recordings/turn.webm");
    }

    public function test_a_disk_whose_root_is_not_there_is_not_taken_for_an_empty_one(): void
    {
        // An unmounted volume: nothing is found, and that must not clear every path in the database.
        $disk = Storage::build(['driver' => 'local', 'root' => "{$this->root}/not-mounted", 'throw' => false]);
        File::deleteDirectory("{$this->root}/not-mounted");

        $this->assertSame(AudioFiles::FAILED, (new AudioFiles)->removeFile($disk, 'debates/1/recordings/turn.webm'));
    }

    private function disk(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => $this->root, 'throw' => false]);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Papers\PaperLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * M4.20: installs the blank official papers (built by tools/papers/clean-templates.php) into storage/app/private/papers.
 * Only the files named in config/papers.php are copied, and each must match its recorded SHA-256; nothing is copied when
 * any file is missing or different.
 */
final class ImportPapers extends Command
{
    protected $signature = 'papers:import {folder : the output folder of tools/papers/clean-templates.php}';

    protected $description = 'Install the blank official papers (checked against config/papers.php)';

    public function handle(): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/\\');
        $wanted = [];
        foreach (config('papers.files', []) as $entry) {
            $wanted[$entry['sha256'].'.'.$entry['format']] = $entry['sha256'];
        }
        if ($wanted === []) {
            $this->error('config/papers.php lists no files.');

            return self::FAILURE;
        }
        $problems = [];
        foreach ($wanted as $name => $sha) {
            $path = $folder.DIRECTORY_SEPARATOR.$name;
            if (! is_file($path)) {
                $problems[] = "missing $name";
            } elseif (! hash_equals($sha, hash_file('sha256', $path))) {
                $problems[] = "SHA-256 differs for $name";
            }
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->error('Nothing was installed.');

            return self::FAILURE;
        }
        $disk = Storage::disk('local');
        $copied = 0;
        foreach (array_keys($wanted) as $name) {
            $target = PaperLibrary::DIRECTORY.'/'.$name;
            if (! $disk->exists($target)) {
                $disk->put($target, (string) file_get_contents($folder.DIRECTORY_SEPARATOR.$name));
                $copied++;
            }
        }
        $this->info(count($wanted)." papers checked, $copied installed in storage/app/private/".PaperLibrary::DIRECTORY.'.');

        return self::SUCCESS;
    }
}

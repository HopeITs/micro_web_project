<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

use App\Services\Identity\IdentityReadException;
use App\Services\Identity\OcrTextParser;
use App\Services\Identity\PageImages;
use Illuminate\Support\Facades\Process;

/**
 * Tesseract OCR on this side (free, no internet, no account): the scan never leaves. It finds the text with a confidence per word and
 * {@see OcrTextParser} takes the fields from it. The independent readings differ in how Tesseract segments the page (psm 6, 11, 4), so a field
 * read differently between them is flagged for review like with the other engines.
 */
final class TesseractEngine implements IdentityEngine
{
    /** The page segmentation mode of each reading: a block of text, sparse text, a single column. */
    private const PSM = [1 => '6', 2 => '11', 3 => '4'];

    public function name(): string
    {
        return 'tesseract';
    }

    public function model(): string
    {
        return 'tesseract';
    }

    public function local(): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        try {
            return Process::timeout(15)->run([(string) config('identity_reader.tesseract.binary'), '--version'])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function passes(): int
    {
        return (int) config('identity_reader.passes');
    }

    public function extract(string $bytes, string $mime, int $pass): array
    {
        $lines = [];
        foreach (PageImages::of($bytes, $mime) as $page) {
            $lines = array_merge($lines, OcrTextParser::lines($this->run($page, $pass)));
        }

        return ['input' => OcrTextParser::toInput($lines), 'input_tokens' => 0, 'output_tokens' => 0];
    }

    /** Runs Tesseract on one page image and returns its TSV. */
    private function run(string $image, int $pass): string
    {
        $file = tempnam(sys_get_temp_dir(), 'idr');
        file_put_contents($file, $image);
        try {
            $result = Process::timeout((int) config('identity_reader.tesseract.timeout'))->run(array_merge(
                [(string) config('identity_reader.tesseract.binary'), $file, 'stdout', '-l', (string) config('identity_reader.tesseract.lang')],
                filled(config('identity_reader.tesseract.tessdata')) ? ['--tessdata-dir', (string) config('identity_reader.tesseract.tessdata')] : [],
                // tessedit_create_tsv works with any tessdata folder (the "tsv" config file lives only in Tesseract's own folder).
                ['--psm', self::PSM[max(1, min(3, $pass))], '--dpi', '300', '-c', 'tessedit_create_tsv=1'],
            ));
        } catch (\Throwable $e) {
            throw new IdentityReadException('unreachable', 0, $e);
        } finally {
            @unlink($file);
        }
        if (! $result->successful()) {
            throw new IdentityReadException(str_contains($result->errorOutput(), 'Failed loading language') ? 'language_missing' : 'engine_failed');
        }

        return $result->output();
    }
}

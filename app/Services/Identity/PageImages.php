<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Facades\Process;

/** The page images of a scan for the engines that read images only: an image as it is; a PDF turned into PNG pages (the first two: front and back) with Poppler. */
final class PageImages
{
    /** @return list<string> image bytes */
    public static function of(string $bytes, string $mime): array
    {
        if ($mime !== 'application/pdf') {
            return [$bytes];
        }
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'idr-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir.DIRECTORY_SEPARATOR.'in.pdf', $bytes);
            try {
                Process::timeout(90)->run([(string) config('identity_reader.pdftoppm'), '-r', '200', '-png', '-f', '1', '-l', '2', $dir.DIRECTORY_SEPARATOR.'in.pdf', $dir.DIRECTORY_SEPARATOR.'page']);
            } catch (\Throwable) {
                throw new IdentityReadException('pdf_unsupported');
            }
            $pages = glob($dir.DIRECTORY_SEPARATOR.'page*.png') ?: [];
            sort($pages);
            if ($pages === []) {
                throw new IdentityReadException('pdf_unsupported');
            }

            return array_map(fn (string $page) => (string) file_get_contents($page), $pages);
        } finally {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}

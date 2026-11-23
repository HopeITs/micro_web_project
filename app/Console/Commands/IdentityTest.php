<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\Engines\EngineFactory;
use App\Services\Identity\IdCardReader;
use App\Services\Identity\IdentityReadException;
use Illuminate\Console\Command;

/**
 * Tries an identity engine on a scan from the server console, to compare engines on real documents before choosing one. It prints the proposed
 * values to the terminal only (nothing is saved, logged or audited): run it on a document you may handle, on the server.
 */
final class IdentityTest extends Command
{
    protected $signature = 'identity:test {file : an image (jpg/png) or a PDF scan} {--driver= : claude, ollama or tesseract (default: the configured one)}';

    protected $description = 'Read one scan with an identity engine and print the proposed values (nothing is saved)';

    public function handle(IdCardReader $reader): int
    {
        $file = (string) $this->argument('file');
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if (! is_file($file) || $mime === null) {
            $this->error('Give an existing jpg, png or pdf file.');

            return self::FAILURE;
        }
        if ($this->option('driver') !== null) {
            if (! in_array($this->option('driver'), EngineFactory::DRIVERS, true)) {
                $this->error('The driver must be one of: '.implode(', ', EngineFactory::DRIVERS));

                return self::FAILURE;
            }
            config(['identity_reader.driver' => $this->option('driver')]);
        }
        $engine = $reader->engine();
        $this->line('Engine: '.$engine->name().' ('.$engine->model().') - '.($engine->local() ? 'read on this server' : 'sent to the cloud service'));
        $started = microtime(true);
        try {
            $result = $reader->read((string) file_get_contents($file), $mime);
        } catch (IdentityReadException $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $rows = [];
        foreach ($result['fields'] as $name => $field) {
            $rows[] = [$name, $field['value'] ?? '-', $field['confidence'], implode(' | ', $field['alternatives']), (string) $field['warning']];
        }
        $this->table(['field', 'value', 'confidence', 'other readings', 'warning'], $rows);
        $this->line(sprintf('Document: %s. Readings: %d. Tokens: %d in / %d out. %.1f s.', $result['type'], $result['passes'], $result['input_tokens'], $result['output_tokens'], microtime(true) - $started));

        return self::SUCCESS;
    }
}

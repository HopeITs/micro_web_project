<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

/**
 * Both free engines on the same scan: the model's and Tesseract's readings are compared field by field, so a value they disagree on is
 * flagged for review. This is what catches a model's confident mistake (a letter in the wrong script, digits in the wrong order) that a second
 * reading by the same model would repeat. Three readings: Tesseract (block text), the model, Tesseract (sparse text). Slower than either alone
 * (the model's reading dominates the time); the scan stays on this server.
 */
final class EnsembleEngine implements IdentityEngine
{
    public function __construct(private TesseractEngine $tesseract, private OllamaEngine $model) {}

    public function name(): string
    {
        return 'ensemble';
    }

    public function model(): string
    {
        return $this->tesseract->model().'+'.$this->model->model();
    }

    public function local(): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        return $this->tesseract->enabled() && $this->model->enabled();
    }

    public function passes(): int
    {
        return 3;
    }

    public function extract(string $bytes, string $mime, int $pass): array
    {
        // Pass 1 and 3 are Tesseract (psm 6 and 4), pass 2 is the model: the engines alternate so each field is read by both kinds.
        return match ($pass) {
            2 => $this->model->extract($bytes, $mime, 1),
            3 => $this->tesseract->extract($bytes, $mime, 3),
            default => $this->tesseract->extract($bytes, $mime, 1),
        };
    }
}

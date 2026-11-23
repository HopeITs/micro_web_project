<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

/** Picks the engine named by `identity_reader.driver` (claude, ollama or tesseract); anything else is the cloud model, which stays off without its key. */
final class EngineFactory
{
    public const DRIVERS = ['claude', 'ollama', 'tesseract'];

    public function make(): IdentityEngine
    {
        return match (strtolower((string) config('identity_reader.driver'))) {
            'ollama' => new OllamaEngine,
            'tesseract' => new TesseractEngine,
            default => new ClaudeEngine,
        };
    }
}

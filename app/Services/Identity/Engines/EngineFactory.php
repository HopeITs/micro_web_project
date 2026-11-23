<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

/** Picks the engine named by `identity_reader.driver` (claude, ollama, tesseract or ensemble); anything else is the cloud model, which stays off without its key. */
final class EngineFactory
{
    public const DRIVERS = ['claude', 'ollama', 'tesseract', 'ensemble'];

    public function make(): IdentityEngine
    {
        return match (strtolower((string) config('identity_reader.driver'))) {
            'ollama' => new OllamaEngine,
            'tesseract' => new TesseractEngine,
            'ensemble' => new EnsembleEngine(new TesseractEngine, new OllamaEngine),
            default => new ClaudeEngine,
        };
    }
}

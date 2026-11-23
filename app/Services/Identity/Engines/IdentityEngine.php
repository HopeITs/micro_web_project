<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

use App\Services\Identity\IdentityPrompt;
use App\Services\Identity\IdentityReadException;

/**
 * Something that reads one scan once and answers in the fixed shape of {@see IdentityPrompt::schema()}
 * (document_type, each field as {value, confidence}, notes). The local checks, the comparison of readings and the staff review are the
 * same for every engine; only how the text is found differs.
 */
interface IdentityEngine
{
    /** The setting value: claude, ollama or tesseract. */
    public function name(): string;

    /** What is logged as the model (the model name, or "tesseract"). */
    public function model(): string;

    /** True when the scan is read on this side and never leaves it. */
    public function local(): bool;

    /** Whether the engine is set up (a key, or the program found). */
    public function enabled(): bool;

    /** How many independent readings one press makes. */
    public function passes(): int;

    /**
     * One reading. `$pass` (1-3) lets an engine vary how it reads, so that independent readings can be compared.
     *
     * @return array{input: array<string, mixed>, input_tokens: int, output_tokens: int}
     *
     * @throws IdentityReadException
     */
    public function extract(string $bytes, string $mime, int $pass): array;
}

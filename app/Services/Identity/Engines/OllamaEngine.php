<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

use App\Services\Identity\IdentityPrompt;
use App\Services\Identity\IdentityReadException;
use App\Services\Identity\PageImages;
use Illuminate\Support\Facades\Http;

/**
 * A vision model that runs on this side through Ollama (free, no internet needed once the model is downloaded): the scan never leaves.
 * The answer is forced into the fixed schema (Ollama "structured output"). A PDF is turned into page images first (Poppler).
 */
final class OllamaEngine implements IdentityEngine
{
    public function name(): string
    {
        return 'ollama';
    }

    public function model(): string
    {
        return (string) config('identity_reader.ollama.model');
    }

    public function local(): bool
    {
        return true;
    }

    public function enabled(): bool
    {
        return filled(config('identity_reader.ollama.url')) && filled(config('identity_reader.ollama.model'));
    }

    public function passes(): int
    {
        return (int) config('identity_reader.passes');
    }

    public function extract(string $bytes, string $mime, int $pass): array
    {
        $images = array_map('base64_encode', PageImages::of($bytes, $mime));
        try {
            $response = Http::acceptJson()->timeout((int) config('identity_reader.ollama.timeout'))
                ->post(rtrim((string) config('identity_reader.ollama.url'), '/').'/api/chat', [
                    'model' => $this->model(), 'stream' => false, 'format' => IdentityPrompt::schema(), 'options' => ['temperature' => 0],
                    'messages' => [
                        ['role' => 'system', 'content' => IdentityPrompt::SYSTEM],
                        ['role' => 'user', 'content' => IdentityPrompt::pass($pass), 'images' => $images],
                    ],
                ]);
        } catch (\Throwable $e) {
            throw new IdentityReadException('unreachable', 0, $e);
        }
        if ($response->failed()) {
            // Ollama answers 404 when the model has not been pulled yet.
            throw new IdentityReadException($response->status() === 404 ? 'model_missing' : 'http_'.$response->status());
        }
        $input = json_decode((string) $response->json('message.content', ''), true);
        if (! is_array($input)) {
            throw new IdentityReadException('no_result');
        }

        return ['input' => $input, 'input_tokens' => (int) $response->json('prompt_eval_count', 0), 'output_tokens' => (int) $response->json('eval_count', 0)];
    }
}

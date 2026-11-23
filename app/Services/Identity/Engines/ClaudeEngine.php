<?php

declare(strict_types=1);

namespace App\Services\Identity\Engines;

use App\Services\Identity\IdentityPrompt;
use App\Services\Identity\IdentityReadException;
use Illuminate\Support\Facades\Http;

/** The cloud vision model (Anthropic Messages API): the scan leaves the server, only when staff press "read". Costs money per reading. */
final class ClaudeEngine implements IdentityEngine
{
    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return (string) config('identity_reader.model');
    }

    public function local(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return filled(config('identity_reader.api_key'));
    }

    public function extract(string $bytes, string $mime, int $pass): array
    {
        $block = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
        try {
            $response = Http::withHeaders(['x-api-key' => (string) config('identity_reader.api_key'), 'anthropic-version' => (string) config('identity_reader.version')])
                ->acceptJson()->timeout((int) config('identity_reader.timeout'))
                ->post((string) config('identity_reader.endpoint'), [
                    'model' => $this->model(), 'max_tokens' => (int) config('identity_reader.max_tokens'),
                    'system' => IdentityPrompt::SYSTEM,
                    'tools' => [['name' => 'record_identity', 'description' => 'Record the fields printed on the identity document.', 'input_schema' => IdentityPrompt::schema()]],
                    'tool_choice' => ['type' => 'tool', 'name' => 'record_identity'],
                    'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => IdentityPrompt::pass($pass)]]]],
                ]);
        } catch (\Throwable $e) {
            // The message of a connection error can carry the URL but never the image; keep only the class.
            throw new IdentityReadException('unreachable', 0, $e);
        }
        if ($response->failed()) {
            throw new IdentityReadException('http_'.$response->status());
        }
        $use = collect($response->json('content', []))->firstWhere('type', 'tool_use');
        if (! is_array($use) || ! is_array($use['input'] ?? null)) {
            throw new IdentityReadException('no_result');
        }

        return ['input' => $use['input'], 'input_tokens' => (int) $response->json('usage.input_tokens', 0), 'output_tokens' => (int) $response->json('usage.output_tokens', 0)];
    }
}

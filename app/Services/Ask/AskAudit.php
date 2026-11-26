<?php

namespace App\Services\Ask;

use App\Models\AiQuery;
use App\Models\WebAccount;

/** Writes one `ai_queries` row per question: the redacted text, the spec that came out and "local" as the engine (nothing leaves the server). */
final class AskAudit
{
    public const ENGINE = 'local';

    public function __construct(private QuestionRedactor $redactor) {}

    public function record(WebAccount $account, string $question, ParseResult $result, int $milliseconds): AiQuery
    {
        return AiQuery::create([
            'account_id' => $account->id,
            'dataset' => $result->dataset,
            'prompt' => $this->redactor->redact($question, $result->unknown),
            'spec' => $result->isEmpty() ? null : $result->spec(),
            'status' => $result->isEmpty() ? 'rejected' : 'ok',
            'model' => self::ENGINE,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'duration_ms' => max(0, $milliseconds),
        ]);
    }
}

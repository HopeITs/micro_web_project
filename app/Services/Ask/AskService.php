<?php

namespace App\Services\Ask;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Support\Datasets\DatasetRegistry;
use Illuminate\Support\Collection;

/** The ask box's entry point: what the account may read, the parse (always local), and the audit row. */
final class AskService
{
    public const MAX_LENGTH = 300;

    public function __construct(private AccountAccess $access, private LocalQueryParser $parser, private AskAudit $audit) {}

    /** @return Collection<int, object> the cities where the account holds every capability the dataset needs (empty: it may not read it) */
    public function cities(WebAccount $account, string $datasetKey): Collection
    {
        $dataset = DatasetRegistry::find($datasetKey);

        return $dataset === null ? collect() : $this->access->citiesWithAll($account, $dataset->viewCapabilities());
    }

    public function allowed(WebAccount $account, string $datasetKey): bool
    {
        return $this->cities($account, $datasetKey)->isNotEmpty();
    }

    /** Parses without writing anything (Ctrl+K typing); null when the account may not read the dataset. */
    public function parse(WebAccount $account, string $datasetKey, string $question): ?ParseResult
    {
        $cities = $this->cities($account, $datasetKey);
        if ($cities->isEmpty()) {
            return null;
        }

        return $this->parser->parse($datasetKey, mb_substr(trim($question), 0, self::MAX_LENGTH), app()->getLocale(), $cities->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Which list a typed question is about: the dataset a word names ("payments", "people"), else the readable one that understands the
     * most of it (projects first on a tie). Nothing is written.
     *
     * @return array{0: string, 1: ParseResult}|null
     */
    public function guess(WebAccount $account, string $question): ?array
    {
        $stems = array_map(AskText::stem(...), AskText::tokenize($question));
        $named = null;
        if (array_intersect($stems, array_map(AskText::stem(...), ['دفعة', 'دفعات', 'قسط', 'أقساط', 'تسديد', 'payment', 'payments', 'installment', 'installments']))) {
            $named = 'installments';
        } elseif (array_intersect($stems, array_map(AskText::stem(...), ['شخص', 'أشخاص', 'ناس', 'مستفيدين', 'people', 'person', 'persons']))) {
            $named = 'people';
        }
        $best = null;
        foreach ($named !== null ? [$named] : array_keys(DatasetRegistry::DATASETS) as $key) {
            $result = $this->parse($account, $key, $question);
            if ($result !== null && ($best === null || count($result->filters) > count($best[1]->filters))) {
                $best = [$key, $result];
            }
        }

        return $best;
    }

    /** Parses and records the (redacted) question in `ai_queries`. */
    public function ask(WebAccount $account, string $datasetKey, string $question): ?ParseResult
    {
        $question = mb_substr(trim($question), 0, self::MAX_LENGTH);
        $started = hrtime(true);
        $result = $this->parse($account, $datasetKey, $question);
        if ($result !== null) {
            $this->audit->record($account, $question, $result, (int) ((hrtime(true) - $started) / 1_000_000));
        }

        return $result;
    }
}

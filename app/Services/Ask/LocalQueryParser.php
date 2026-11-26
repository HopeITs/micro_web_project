<?php

namespace App\Services\Ask;

use App\Models\City;
use App\Models\Donor;
use App\Models\DonorGroup;
use App\Models\EducationLevel;
use App\Models\FundType;
use App\Models\MaritalStatus;
use App\Models\ProjectState;
use App\Models\ProjectType;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\DatasetRegistry;
use App\Support\ProjectStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The ask box's parser (X1, owner decision of 26 Nov 2026: nothing leaves the server). A plain question in Arabic or English
 * becomes a QuerySpec-shaped array and the list of words that were not understood. It is rule based and deterministic: lookup
 * values are found by name in the database (Arabic letters folded as ArabicSearch does), numbers, ages, amounts and dates by
 * patterns, and every filter is checked by QuerySpec::filterErrors() against the dataset's field registry, so a field, operator
 * or value that is not in the registry cannot be produced. Cities outside $allowedCityIds are never filtered on.
 */
final class LocalQueryParser
{
    /** field key => lookup model whose names (Arabic and English) the question may use */
    private const FAMILIES = [
        'city' => City::class, 'state' => ProjectState::class, 'project_type' => ProjectType::class, 'fund_type' => FundType::class,
        'donor' => Donor::class, 'group' => DonorGroup::class, 'marital_status' => MaritalStatus::class, 'education' => EducationLevel::class,
    ];

    /** Families where one word of a long name may stand for it when no other value has that word. */
    private const PARTIAL = ['donor', 'group', 'project_type', 'fund_type'];

    /**
     * @param  list<int>  $allowedCityIds  the cities the account may read this dataset in
     */
    public function parse(string $datasetKey, string $question, string $locale, array $allowedCityIds, ?CarbonImmutable $today = null): ParseResult
    {
        $dataset = DatasetRegistry::find($datasetKey) ?? throw new InvalidArgumentException("Unknown dataset [{$datasetKey}].");
        $allowed = array_values(array_map('intval', $allowedCityIds));
        [$lex, $enIndex, $partial, $codes] = $this->vocabulary($dataset);

        return (new AskScan($dataset, $lex, $enIndex, $partial, $allowed, $codes, $today ?? CarbonImmutable::today()))->run($question);
    }

    /**
     * @return array{0: AskLexicon, 1: array<string, array{0: string, 1: int}>, 2: array<string, array<string, list<string>>>, 3: list<string>}
     */
    private function vocabulary(Dataset $dataset): array
    {
        $lex = AskLexicon::fixed();
        $enIndex = [];
        $partial = [];
        $codes = City::query()->pluck('code')->map(fn ($c) => strtoupper((string) $c))->all();

        foreach (self::FAMILIES as $field => $model) {
            $f = $dataset->field($field);
            if ($f === null || ! $f->filterable) {
                continue;
            }
            foreach ($model::query()->get() as $row) {
                $id = (int) $row->id;
                foreach ($this->variants((string) $row->name_ar, $field) as $phrase) {
                    $lex->add($phrase, 'entity', [$field, $id]);
                }
                foreach ($this->variants((string) ($row->name_en ?? ''), $field) as $phrase) {
                    $lex->add($phrase, 'entity', [$field, $id]);
                }
                if ($row->name_en) {
                    $enIndex[(string) $row->name_en] ??= [$field, $id];
                }
                if ($field === 'city' && $row->code) {
                    $lex->add((string) $row->code, 'entity', [$field, $id]);
                }
                if (in_array($field, self::PARTIAL, true)) {
                    foreach ([(string) $row->name_ar, (string) ($row->name_en ?? '')] as $name) {
                        foreach (AskText::tokenize($name) as $word) {
                            $stem = AskText::stem($word);
                            if (mb_strlen($stem) >= 4) {
                                $partial[$field][$stem] = array_values(array_unique([...($partial[$field][$stem] ?? []), (string) $id]));
                            }
                        }
                    }
                }
            }
        }

        if ($dataset->field('state') !== null) {
            $states = ProjectState::query()->pluck('id', 'name_en');
            foreach (ProjectStatus::groups() as $group => $names) {
                $ids = array_values(array_filter(array_map(fn ($n) => $states[$n] ?? null, $names)));
                if ($ids === []) {
                    continue;
                }
                foreach (['ar', 'en'] as $loc) {
                    $label = trans('hc.path_group_'.$group, [], $loc);
                    $lex->add('مرحلة '.$label, 'entity', ['state', $ids]);
                    $lex->add($label.' stage', 'entity', ['state', $ids]);
                    $lex->add('stage '.$label, 'entity', ['state', $ids]);
                }
            }
        }

        return [$lex, $enIndex, $partial, $codes];
    }

    /**
     * The phrases one stored name answers to: the name itself; "عازب/ة" as عازب and عازبة; a name joined with "or" / "أو"
     * also by each part when the family is a status list.
     *
     * @return list<string>
     */
    private function variants(string $name, string $field): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        $out = [$name];
        if (str_contains($name, '/')) {
            $base = trim(explode('/', $name)[0]);
            $out = [$base, $base.'ة', $name];
        }
        if (in_array($field, ['marital_status', 'education'], true) && preg_match('/\s(أو|or)\s/u', $name) === 1) {
            foreach (preg_split('/\s(?:أو|or)\s/u', $name) ?: [] as $part) {
                if (mb_strlen(trim($part)) >= 3) {
                    $out[] = trim($part);
                }
            }
        }

        return array_values(array_unique($out));
    }
}

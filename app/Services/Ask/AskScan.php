<?php

namespace App\Services\Ask;

use App\Support\Datasets\Dataset;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\ReportSpec;
use Carbon\CarbonImmutable;

/**
 * One left-to-right pass over a question (X1). Every phrase it understands becomes a filter candidate on a field of the
 * dataset; nothing it does not understand is guessed. Candidates are checked by QuerySpec::filterErrors() before they are
 * kept, so a field, operator or value outside the dataset's registry cannot come out. Used once per question.
 */
final class AskScan
{
    private const SILENT = ['stop', 'sep', 'tunit', 'mult', 'dual', 'fieldword', 'with', 'neg', 'group', 'measure', 'last', 'next', 'pnum', 'datefield', 'from', 'to'];

    /** @var list<array{raw: string, alts: list<string>, num: ?float, int: bool, used: bool}> */
    private array $t = [];

    /** @var array<string, array{field: string, op: string, values: list<mixed>}> */
    private array $filters = [];

    /** @var list<string> */
    private array $search = [];

    private bool $neg = false;

    private ?string $dateField = null;

    private ?string $rows = null;

    private ?string $columns = null;

    private ?string $measure = null;

    /**
     * @param  array<string, array{0: string, 1: int|string}>  $enIndex  English name => [field, value] of the lookup families (synonyms)
     * @param  array<string, array<string, list<string>>>  $partial  field => word stem => values (a single word that names exactly one value)
     * @param  list<int>  $allowedCities
     * @param  list<string>  $cityCodes
     */
    public function __construct(
        private Dataset $dataset,
        private AskLexicon $lex,
        private array $enIndex,
        private array $partial,
        private array $allowedCities,
        private array $cityCodes,
        private CarbonImmutable $today,
    ) {}

    public function run(string $question): ParseResult
    {
        foreach (AskText::tokenize(mb_substr($question, 0, 400)) as $word) {
            $num = null;
            if (preg_match('/^\d+(\.\d+)?$/', $word) === 1) {
                $num = (float) $word;
            } elseif (preg_match('/^(\d+(?:\.\d+)?)k$/i', $word, $m) === 1) {
                $num = (float) $m[1] * 1000;
            }
            $this->t[] = ['raw' => $word, 'alts' => AskText::alternatives($word), 'num' => $num, 'int' => preg_match('/^\d+$/', $word) === 1, 'used' => false];
        }
        $this->detectDateField();

        $n = count($this->t);
        for ($i = 0; $i < $n;) {
            if ($this->t[$i]['used']) {
                $i++;

                continue;
            }
            [$len, $kind] = $this->handle($i);
            if ($len > 0) {
                $this->use($i, $len);
                $i += $len;
                if (! in_array($kind, ['entity', 'sep', 'neg'], true)) {
                    $this->neg = false;
                }

                continue;
            }
            $this->neg = false;
            $i++;
        }

        return $this->finish();
    }

    // ---------------------------------------------------------------- one step

    /** @return array{0: int, 1: string} words consumed and the kind of phrase */
    private function handle(int $i): array
    {
        foreach ([
            'group' => fn () => $this->groupAt($i),
            'range' => fn () => $this->rangeAt($i),
            'date' => fn () => $this->dateAt($i),
            'pay' => fn () => $this->payAt($i),
            'pwith' => fn () => $this->peopleProjectsAt($i),
            'bare' => fn () => $this->bareNumberAt($i),
            'number' => fn () => $this->projectNumbersAt($i),
            'neg' => fn () => $this->negAt($i),
            'with' => fn () => $this->withAt($i),
            'entity' => fn () => $this->entityAt($i),
            'currency' => fn () => $this->currencyAt($i),
            'measure' => fn () => $this->measureAt($i),
        ] as $kind => $try) {
            $len = $try();
            if ($len !== null && $len > 0) {
                return [$len, $kind];
            }
        }
        if ($this->lex->matchAt($this->t, $i, ['stop', 'sep']) !== null) {
            $m = $this->lex->matchAt($this->t, $i, ['sep', 'stop']);

            return [$m['len'], $m['kind']];
        }

        return [0, ''];
    }

    private function use(int $i, int $len): void
    {
        for ($k = $i; $k < $i + $len && isset($this->t[$k]); $k++) {
            $this->t[$k]['used'] = true;
        }
    }

    private function has(string $field): bool
    {
        $f = $this->dataset->field($field);

        return $f !== null && $f->filterable;
    }

    // ---------------------------------------------------------------- filters

    /** Adds a candidate when it passes QuerySpec; one filter per field, so a second one on the same field is merged. */
    private function add(string $field, string $op, array $values): bool
    {
        $filter = ['field' => $field, 'op' => $op, 'values' => array_values($values)];
        if (! $this->has($field) || QuerySpec::filterErrors($this->dataset, $filter) !== []) {
            return false;
        }
        $old = $this->filters[$field] ?? null;
        if ($old !== null) {
            $filter = $this->merge($old, $filter);
        }
        $this->filters[$field] = $filter;

        return true;
    }

    private function merge(array $old, array $new): array
    {
        $type = $this->dataset->field($new['field'])->type;
        if (in_array($new['op'], ['in', 'not_in'], true) && in_array($old['op'], ['in', 'not_in'], true)) {
            if ($old['op'] === $new['op']) {
                return ['field' => $new['field'], 'op' => $new['op'], 'values' => array_values(array_unique([...$old['values'], ...$new['values']]))];
            }
            if ($old['op'] === 'in') {
                $left = array_values(array_diff($old['values'], $new['values']));

                return $left === [] ? $new : ['field' => $new['field'], 'op' => 'in', 'values' => $left];
            }

            return $new;
        }
        if ($type === 'date') {
            $a = $this->rangeOf($old);
            $b = $this->rangeOf($new);
            if ($a !== null && $b !== null) {
                return ['field' => $new['field'], 'op' => 'between', 'values' => [min($a[0], $b[0]), max($a[1], $b[1])]];
            }
        }
        if (in_array($type, ['number', 'money'], true)) {
            $lo = $this->bounds($old);
            $hi = $this->bounds($new);
            $from = $lo[0] ?? $hi[0];
            $to = $lo[1] ?? $hi[1];
            $from = $lo[0] !== null && $hi[0] !== null ? max($lo[0], $hi[0]) : $from;
            $to = $lo[1] !== null && $hi[1] !== null ? min($lo[1], $hi[1]) : $to;
            if ($from !== null || $to !== null) {
                $step = $type === 'number' ? 1 : 0;
                $from = $from !== null && $old['op'] === 'gt' || $from !== null && $new['op'] === 'gt' ? $from + $step : $from;
                $to = $to !== null && ($old['op'] === 'lt' || $new['op'] === 'lt') ? $to - $step : $to;

                return ['field' => $new['field'], 'op' => 'between', 'values' => [$from, $to]];
            }
        }

        return $new;
    }

    /** @return array{0: ?float, 1: ?float} lower and upper bound of a numeric filter */
    private function bounds(array $f): array
    {
        $v = $f['values'];

        return match ($f['op']) {
            'gt' => [(float) $v[0], null],
            'lt' => [null, (float) $v[0]],
            'between' => [($v[0] ?? null) === null || $v[0] === '' ? null : (float) $v[0], ($v[1] ?? null) === null || $v[1] === '' ? null : (float) $v[1]],
            'in' => [(float) min($v), (float) max($v)],
            default => [null, null],
        };
    }

    /** @return array{0: string, 1: string}|null */
    private function rangeOf(array $f): ?array
    {
        if ($f['op'] !== 'between') {
            return null;
        }
        if (count($f['values']) === 1) {
            return QuerySpec::presetRange((string) $f['values'][0], $this->today);
        }

        return [(string) ($f['values'][0] ?? '0000-01-01') ?: '0000-01-01', (string) ($f['values'][1] ?? '9999-12-31') ?: '9999-12-31'];
    }

    // ---------------------------------------------------------------- entities, negation, empties

    private function negAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['entity', 'entity_en', 'pay', 'pwith', 'neg']);
        if ($m === null || $m['kind'] !== 'neg') {
            return null;
        }
        $j = $i + $m['len'];
        $bare = $this->lex->matchAt($this->t, $i, ['neg']);
        $without = in_array($this->t[$i]['alts'][0], [AskText::stem('بدون'), AskText::stem('بلا'), 'without', 'no', AskText::stem('من')], true);
        $field = $this->lex->matchAt($this->t, $j, ['fieldword']);
        if ($field !== null && $without) {
            $key = $this->fieldKey((string) $field['payloads'][0]);
            if ($key !== null && $this->add($key, 'empty', [])) {
                return $m['len'] + $field['len'];
            }
        }
        $this->neg = $bare !== null;

        return $m['len'];
    }

    private function withAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['pwith', 'with']);
        if ($m === null || $m['kind'] !== 'with') {
            return null;
        }
        $field = $this->lex->matchAt($this->t, $i + $m['len'], ['fieldword']);
        $key = $field === null ? null : $this->fieldKey((string) $field['payloads'][0]);
        if ($key !== null && $this->add($key, 'not_empty', [])) {
            return $m['len'] + $field['len'];
        }

        return null;
    }

    private function fieldKey(string $word): ?string
    {
        if ($word === 'state' && $this->dataset->field('status') !== null && $this->dataset->key() === 'installments') {
            $word = 'status';
        }

        return $this->dataset->field($word) !== null ? $word : null;
    }

    private function entityAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['entity', 'entity_en']);
        $byField = [];
        $len = 0;
        if ($m !== null) {
            $len = $m['len'];
            foreach ($m['payloads'] as $payload) {
                if ($m['kind'] === 'entity_en') {
                    $payload = $this->enIndex[$payload] ?? null;
                }
                if (! is_array($payload) || ! $this->has($payload[0])) {
                    continue;
                }
                foreach ((array) $payload[1] as $value) {
                    $byField[$payload[0]][] = (string) $value;
                }
            }
        } else {
            $stem = $this->t[$i]['alts'];
            foreach ($this->partial as $field => $stems) {
                foreach ($stem as $alt) {
                    if (mb_strlen($alt) >= 4 && isset($stems[$alt]) && count($stems[$alt]) === 1 && $this->has($field)) {
                        $byField[$field][] = $stems[$alt][0];
                    }
                }
            }
            $len = 1;
        }
        if ($byField === []) {
            return null;
        }
        if (isset($byField['fund_type']) && $this->lex->matchAt($this->t, $i + $len, ['gt', 'lt', 'ge', 'le', 'between']) !== null) {
            unset($byField['fund_type']); // "قرض أكثر من 1000": the word is the loan amount, not the fund type
        }
        $done = 0;
        foreach ($byField as $field => $values) {
            if ($field === 'city') {
                $values = array_values(array_filter($values, fn ($v) => in_array((int) $v, $this->allowedCities, true)));
                if ($values === []) {
                    continue; // a city this account may not read: the word stays unused and is reported as not understood
                }
            }
            if ($this->add($field, $this->neg ? 'not_in' : 'in', array_values(array_unique($values)))) {
                $done++;
            }
        }

        return $done > 0 ? $len : null;
    }

    private function payAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['pay']);
        if ($m === null) {
            return null;
        }
        $what = $m['payloads'][0];
        $ok = false;
        if ($this->has('status')) {
            $ok = match ($what) {
                'overdue' => $this->add('status', 'in', ['overdue']),
                'unpaid' => $this->add('status', 'not_in', ['paid']),
                default => $this->add('status', 'in', ['paid']),
            };
        } elseif ($what === 'overdue' && $this->has('overdue_installments')) {
            $ok = $this->add('overdue_installments', 'gt', [0]);
        }

        return $ok ? $m['len'] : null;
    }

    /** A currency word on its own ("بالدولار", "in USD") filters the currency where the dataset has one. */
    private function currencyAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['currencyword']);

        return $m !== null && $this->add('currency', 'in', [$m['payloads'][0]]) ? $m['len'] : null;
    }

    private function peopleProjectsAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['pwith']);

        return $m !== null && $this->add('has_projects', 'in', [$m['payloads'][0]]) ? $m['len'] : null;
    }

    // ---------------------------------------------------------------- numbers and comparisons

    /** @return array{type: string, len: int, value?: float, start?: string, end?: string, year?: bool, unit?: string}|null */
    private function operandAt(int $j): ?array
    {
        if (! isset($this->t[$j])) {
            return null;
        }
        $raw = $this->t[$j]['raw'];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1 || preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $raw, $m) === 1 || preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m) === 1) {
            [$y, $mo, $d] = strlen($m[1]) === 4 ? [$m[1], $m[2], $m[3]] : [$m[3], $m[2], $m[1]];
            if (checkdate((int) $mo, (int) $d, (int) $y)) {
                $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);

                return ['type' => 'date', 'len' => 1, 'start' => $date, 'end' => $date];
            }
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return ['type' => 'range', 'len' => 1] + $this->monthRange((int) $m[1], (int) $m[2]);
        }
        if (($rel = $this->lex->matchAt($this->t, $j, ['rel'])) !== null) {
            return ['type' => 'range', 'len' => $rel['len']] + $this->relRange($rel['payloads'][0]);
        }
        $month = $this->lex->matchAt($this->t, $j, ['month']);
        $monthNo = $month === null ? null : (int) $month['payloads'][0];
        if ($month !== null && $monthNo < 0) {
            $next = $this->t[$j + 1] ?? null;
            $prev = $this->lex->matchAt($this->t, $j - 1, ['tunit']);
            $monthNo = ($next !== null && $next['int'] && $next['num'] >= 1990) || ($j > 0 && $prev !== null && $prev['payloads'][0] === 'month') ? 8 : null;
        }
        if ($monthNo !== null) {
            return $this->monthOperand($j + $month['len'], $monthNo, $month['len']);
        }
        if (($unit = $this->lex->matchAt($this->t, $j, ['tunit'])) !== null && $unit['payloads'][0] === 'month' && ($this->t[$j + $unit['len']]['int'] ?? false)
            && $this->t[$j + $unit['len']]['num'] >= 1 && $this->t[$j + $unit['len']]['num'] <= 12) {
            return $this->monthOperand($j + $unit['len'] + 1, (int) $this->t[$j + $unit['len']]['num'], $unit['len'] + 1);
        }
        if (($dual = $this->lex->matchAt($this->t, $j, ['dual'])) !== null && $dual['payloads'][0][1] === 'project') {
            return ['type' => 'num', 'len' => $dual['len'], 'value' => 2.0, 'unit' => 'project'];
        }
        if ($this->t[$j]['num'] !== null) {
            $value = $this->t[$j]['num'];
            $len = 1;
            $mult = $this->lex->matchAt($this->t, $j + 1, ['mult']);
            if ($mult !== null) {
                $value *= (float) $mult['payloads'][0];
                $len += $mult['len'];
            }

            return ['type' => 'num', 'len' => $len, 'value' => $value, 'year' => $this->t[$j]['int'] && $mult === null && $value >= 1990 && $value <= 2100];
        }

        return null;
    }

    /** @return array{type: string, len: int, start: string, end: string} */
    private function monthOperand(int $yearAt, int $month, int $len): array
    {
        $year = $this->today->year;
        if (($this->t[$yearAt]['int'] ?? false) && $this->t[$yearAt]['num'] >= 1990 && $this->t[$yearAt]['num'] <= 2100) {
            $year = (int) $this->t[$yearAt]['num'];
            $len++;
        }

        return ['type' => 'range', 'len' => $len] + $this->monthRange($year, $month);
    }

    /** @return array{start: string, end: string} */
    private function monthRange(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return ['start' => $start->toDateString(), 'end' => $start->endOfMonth()->toDateString()];
    }

    /** @return array{start: string, end: string} */
    private function relRange(string $rel): array
    {
        $t = $this->today;
        [$a, $b] = match ($rel) {
            'this_month' => [$t->startOfMonth(), $t->endOfMonth()],
            'last_month' => [$t->subMonthNoOverflow()->startOfMonth(), $t->subMonthNoOverflow()->endOfMonth()],
            'next_month' => [$t->addMonthNoOverflow()->startOfMonth(), $t->addMonthNoOverflow()->endOfMonth()],
            'this_year' => [$t->startOfYear(), $t->endOfYear()],
            'last_year' => [$t->subYear()->startOfYear(), $t->subYear()->endOfYear()],
            'next_year' => [$t->addYear()->startOfYear(), $t->addYear()->endOfYear()],
            'this_week' => [$t->startOfWeek(), $t->endOfWeek()],
            'last_week' => [$t->subWeek()->startOfWeek(), $t->subWeek()->endOfWeek()],
            'yesterday' => [$t->subDay(), $t->subDay()],
            default => [$t, $t],
        };

        return ['start' => $a->toDateString(), 'end' => $b->toDateString()];
    }

    private function rangeAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['gt', 'lt', 'ge', 'le', 'between', 'from', 'after', 'before']);
        if ($m === null) {
            return null;
        }
        $kind = $m['kind'];
        $j = $i + $m['len'];
        $a = $this->operandAt($j);
        if ($a === null) {
            return null;
        }
        $end = $j + $a['len'];
        $b = null;
        if (in_array($kind, ['between', 'from'], true)) {
            $sep = $this->lex->matchAt($this->t, $end, ['to', 'sep', 'le']);
            $b = $sep === null ? null : $this->operandAt($end + $sep['len']);
            if ($b !== null) {
                $end += $sep['len'] + $b['len'];
            } elseif ($kind === 'between' || $a['type'] === 'num') {
                return null;
            }
        }
        $dateish = fn (?array $x) => $x !== null && in_array($x['type'], ['date', 'range'], true);

        if (in_array($kind, ['after', 'before'], true) || ($a['type'] !== 'num' && ! in_array($kind, ['between', 'from'], true)) || ($kind === 'le' && $dateish($a))) {
            $a = $this->asDate($a);
            if ($a === null) {
                return null;
            }

            return $this->dateCompare($kind === 'from' ? 'ge' : $kind, $a, null) ? $end - $i : null;
        }
        if (in_array($kind, ['between', 'from'], true)) {
            $yearsOnly = $a['type'] === 'num' && ! empty($a['year']) && ($b === null || ($b['type'] === 'num' && ! empty($b['year'])));
            $unitAfter = $this->lex->matchAt($this->t, $end, ['unit', 'mult']) !== null;
            if ($dateish($a) || $dateish($b) || ($yearsOnly && ! $unitAfter)) {
                $a = $this->asDate($a);
                $b2 = $b === null ? null : $this->asDate($b);
                if ($a === null || ($b !== null && $b2 === null)) {
                    return null;
                }

                return $this->dateCompare($b2 === null ? 'ge' : 'between', $a, $b2) ? $end - $i : null;
            }
            if ($a['type'] === 'num' && $b !== null && $b['type'] === 'num') {
                return $this->numeric($i, $end, 'between', $a, $b) ? $end - $i : null;
            }

            return null;
        }

        return $this->numeric($i, $end, $kind, $a, null) ? $end - $i : null;
    }

    /** A number that is a year becomes the year's range. @return array{type: string, start: string, end: string}|null */
    private function asDate(array $x): ?array
    {
        if ($x['type'] !== 'num') {
            return $x;
        }
        if (empty($x['year'])) {
            return null;
        }
        $y = (int) $x['value'];

        return ['type' => 'range', 'len' => $x['len'], 'start' => sprintf('%04d-01-01', $y), 'end' => sprintf('%04d-12-31', $y)];
    }

    private function dateCompare(string $kind, array $a, ?array $b): bool
    {
        $field = $this->dateField;
        if ($field === null) {
            return false;
        }

        return match ($kind) {
            'after', 'gt' => $this->add($field, 'gt', [$a['end']]),
            'before', 'lt' => $this->add($field, 'lt', [$a['start']]),
            'ge' => $this->add($field, 'between', [$a['start'], null]),
            'le' => $this->add($field, 'between', [null, $a['end']]),
            default => $this->addDate($a['start'], $b === null ? null : $b['end'], $b === null ? $a['end'] : null),
        };
    }

    /** Adds a date range; a single range uses the presets when it is exactly one. */
    private function addDate(?string $start, ?string $end, ?string $single = null): bool
    {
        $field = $this->dateField;
        if ($field === null) {
            return false;
        }
        $end ??= $single;
        if ($start !== null && $end !== null) {
            foreach (['this_month', 'this_year'] as $preset) {
                if (QuerySpec::presetRange($preset, $this->today) === [$start, $end]) {
                    return $this->add($field, 'between', [$preset]);
                }
            }
        }

        return $this->add($field, 'between', [$start, $end]);
    }

    /** Applies a number phrase to the field its unit words point to. */
    private function numeric(int $from, int $to, string $kind, array $a, ?array $b): bool
    {
        $target = $this->numericTarget($from, $to, $a['unit'] ?? null);
        if ($target === null) {
            return false;
        }
        [$field, $currency, $hints] = $target;
        $x = self::whole($a['value']);
        $y = isset($b['value']) ? self::whole($b['value']) : null;
        $ok = match ($kind) {
            'gt' => $this->add($field, 'gt', [$x]),
            'lt' => $this->add($field, 'lt', [$x]),
            'ge' => $this->add($field, 'between', [$x, null]),
            'le' => $this->add($field, 'between', [null, $x]),
            'between' => $this->add($field, 'between', [min($x, $y), max($x, $y)]),
            default => $this->dataset->field($field)->type === 'number' ? $this->add($field, 'in', [$x]) : $this->add($field, 'between', [$x, $x]),
        };
        if ($ok) {
            foreach ($hints as [$p, $len]) {
                $this->use($p, $len);
            }
            if ($currency !== null) {
                $this->add('currency', 'in', [$currency]);
            }
        }

        return $ok;
    }

    private static function whole(float $v): int|float
    {
        return floor($v) === $v && abs($v) < 1e15 ? (int) $v : $v;
    }

    /** @return array{0: string, 1: ?string, 2: list<array{0: int, 1: int}>}|null field, currency, the unit words used */
    private function numericTarget(int $from, int $to, ?string $forced): ?array
    {
        $hints = [];
        $positions = [...range(max(0, $from - 3), max(0, $from - 1)), ...range($to, $to + 2)];
        if ($from === 0) {
            $positions = range($to, $to + 2);
        }
        foreach ($positions as $p) {
            $m = $this->lex->matchAt($this->t, $p, ['unit', 'tunit']);
            if ($m !== null && ! $this->t[$p]['used']) {
                foreach ($m['payloads'] as $payload) {
                    $hints[$payload][] = [$p, $m['len']];
                }
            }
        }
        if ($forced !== null) {
            $hints[$forced][] = [$from, 0];
        }
        if (! ($this->dataset->field('projects_count') !== null)) {
            unset($hints['project']);
        }
        $has = fn (string $f) => $this->dataset->field($f) !== null && $this->dataset->field($f)->filterable;
        $key = $this->dataset->key();
        $amount = $key === 'installments' ? 'amount' : 'requested_amount';
        $choice = match (true) {
            isset($hints['project']) && $has('projects_count') => ['projects_count', ['project']],
            isset($hints['age']) && $has('age') => ['age', ['age']],
            isset($hints['day']) && $has('days_overdue') => ['days_overdue', ['day']],
            isset($hints['loan']) && $has('loan_amount') => ['loan_amount', ['loan', 'usd', 'syp', 'amount']],
            isset($hints['requested']) && $has('requested_amount') => ['requested_amount', ['requested', 'usd', 'syp', 'amount']],
            (isset($hints['usd']) || isset($hints['syp']) || isset($hints['amount'])) && $has($amount) => [$amount, ['usd', 'syp', 'amount']],
            default => null,
        };
        if ($choice === null) {
            if ($hints !== [] && ! array_intersect(array_keys($hints), ['usd', 'syp', 'amount', 'requested', 'loan'])) {
                return null; // a unit the dataset has no field for (e.g. years on payments)
            }
            $default = match ($key) {
                'people' => 'age',
                'installments' => 'amount',
                default => 'requested_amount',
            };
            if (! $has($default)) {
                return null;
            }
            $choice = [$default, ['usd', 'syp', 'amount', 'requested']];
        }
        [$field, $kinds] = $choice;
        $used = [];
        $currency = null;
        foreach ($kinds as $k) {
            foreach ($hints[$k] ?? [] as [$p, $len]) {
                if ($len > 0 && ($this->t[$p]['used'] ?? false) === false) {
                    if (in_array($k, ['usd', 'syp'], true)) {
                        if ($has('currency')) {
                            $currency = strtoupper($k);
                            $used[] = [$p, $len];
                        }
                    } else {
                        $used[] = [$p, $len];
                    }
                }
            }
        }

        return [$field, $currency, $used];
    }

    private function bareNumberAt(int $i): ?int
    {
        $a = $this->operandAt($i);
        if ($a === null || $a['type'] !== 'num') {
            return null;
        }
        $end = $i + $a['len'];
        $before = $i > 0 && ! $this->t[$i - 1]['used'] ? $this->lex->matchAt($this->t, $i - 1, ['unit']) : null;
        $hasUnit = $this->lex->matchAt($this->t, $end, ['unit']) !== null || ($before !== null && $before['payloads'][0] === 'age') || isset($a['unit']);
        if (! $hasUnit) {
            return null;
        }

        return $this->numeric($i, $end, 'eq', $a, null) ? $end - $i : null;
    }

    private function projectNumbersAt(int $i): ?int
    {
        if (! in_array($this->dataset->key(), ['projects', 'installments'], true)) {
            return null;
        }
        $j = $i;
        $marked = false;
        while (($m = $this->lex->matchAt($this->t, $j, ['pnum'])) !== null) {
            $j += $m['len'];
            $marked = true;
        }
        $terms = [];
        while (isset($this->t[$j])) {
            $raw = $this->t[$j]['raw'];
            $term = null;
            $len = 1;
            if (preg_match('/^([a-z]{3})-?(\d{1,9})$/i', $raw, $m) === 1 && in_array(strtoupper($m[1]), $this->cityCodes, true)) {
                $term = strtoupper($m[1]).'-'.(int) $m[2];
            } elseif (preg_match('/^[a-z]{3}$/i', $raw) === 1 && in_array(strtoupper($raw), $this->cityCodes, true) && ($this->t[$j + 1]['int'] ?? false) && strlen($this->t[$j + 1]['raw']) >= 3 && strlen($this->t[$j + 1]['raw']) <= 9) {
                $term = strtoupper($raw).'-'.(int) $this->t[$j + 1]['raw'];
                $len = 2;
            } elseif ($this->t[$j]['int'] && strlen($raw) >= 3 && strlen($raw) <= 9 && $this->lex->matchAt($this->t, $j + 1, ['unit', 'mult', 'tunit']) === null) {
                $term = (string) (int) $raw;
            }
            if ($term === null) {
                break;
            }
            $terms[] = $term;
            $j += $len;
            while (($sep = $this->lex->matchAt($this->t, $j, ['sep'])) !== null && isset($this->t[$j + $sep['len']]) && $this->t[$j + $sep['len']]['num'] !== null) {
                $j += $sep['len'];
            }
        }
        if ($terms === []) {
            return null;
        }
        array_push($this->search, ...$terms);

        return $j - $i;
    }

    // ---------------------------------------------------------------- dates

    private function detectDateField(): void
    {
        $default = match ($this->dataset->key()) {
            'people' => 'registered',
            'installments' => 'due_on',
            default => 'request_date',
        };
        $chosen = null;
        foreach ($this->t as $i => $_) {
            $m = $this->lex->matchAt($this->t, $i, ['datefield']);
            if ($m !== null && $this->dataset->field((string) $m['payloads'][0]) !== null && $this->has((string) $m['payloads'][0])) {
                $chosen ??= (string) $m['payloads'][0];
                $this->use($i, $m['len']);
            }
        }
        $this->dateField = $chosen ?? ($this->has($default) ? $default : null);
    }

    private function dateAt(int $i): ?int
    {
        if ($this->dateField === null) {
            return null;
        }
        // last / within N units, next N units, N units next
        $last = $this->lex->matchAt($this->t, $i, ['last']);
        if ($last !== null && $this->lex->matchAt($this->t, $i, ['rel']) === null) {
            $j = $i + $last['len'];
            [$n, $unit, $len] = $this->countUnit($j);
            if ($unit !== null && $len > 0) {
                $forward = $this->dataset->key() === 'installments' && in_array($this->t[$i]['alts'][0], [AskText::stem('خلال'), 'within'], true);

                return $this->rolling($n, $unit, $forward) ? $last['len'] + $len : null;
            }
        }
        $next = $this->lex->matchAt($this->t, $i, ['next']);
        if ($next !== null && $this->lex->matchAt($this->t, $i, ['rel']) === null) {
            [$n, $unit, $len] = $this->countUnit($i + $next['len']);
            if ($unit !== null && $len > 0) {
                return $this->rolling($n, $unit, true) ? $next['len'] + $len : null;
            }
        }
        if ($this->t[$i]['num'] !== null) {
            [$n, $unit, $len] = $this->countUnit($i);
            if ($unit !== null && $len > 0 && ($after = $this->lex->matchAt($this->t, $i + $len, ['next'])) !== null) {
                return $this->rolling($n, $unit, true) ? $len + $after['len'] : null;
            }
        }
        if ($this->dateField === 'birth_date' && $this->t[$i]['int'] && strlen($this->t[$i]['raw']) === 4 && $this->t[$i]['num'] >= 1900 && $this->t[$i]['num'] <= 2100) {
            $y = (int) $this->t[$i]['num'];

            return $this->addDate(sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)) ? 1 : null;
        }
        $a = $this->operandAt($i);
        if ($a === null || $a['type'] === 'num' && empty($a['year'])) {
            return null;
        }
        if ($a['type'] === 'num') {
            $prev = $i > 0 ? $this->t[$i - 1] : null;
            $marker = $prev !== null && (array_intersect($prev['alts'], [AskText::stem('سنة'), AskText::stem('عام'), AskText::stem('لعام'), AskText::stem('لسنة'), AskText::stem('في'), 'in', 'year', 'of', 'during']) !== []);
            $numberMarker = $prev !== null && array_intersect($prev['alts'], [AskText::stem('رقم'), AskText::stem('ارقام'), 'number', 'numbers', 'no', AskText::stem('مشروع'), 'project']) !== [];
            $unitAfter = $this->lex->matchAt($this->t, $i + $a['len'], ['unit', 'mult']);
            $unitAfter = $unitAfter !== null && $unitAfter['payloads'][0] !== 'age';
            if ($numberMarker || $unitAfter || $a['len'] > 1 || (! $marker && $a['value'] < 2000)) {
                return null;
            }
            $a = $this->asDate($a);
        }

        return $this->addDate($a['start'], $a['end']) ? $a['len'] : null;
    }

    /** @return array{0: int, 1: ?string, 2: int} count, unit (day|week|month|year) and words used from $j */
    private function countUnit(int $j): array
    {
        $dual = $this->lex->matchAt($this->t, $j, ['dual']);
        if ($dual !== null && $dual['payloads'][0][1] !== 'project') {
            return [2, $dual['payloads'][0][1], $dual['len']];
        }
        if (($this->t[$j]['num'] ?? null) !== null && $this->t[$j]['int']) {
            $unit = $this->lex->matchAt($this->t, $j + 1, ['tunit']);

            return $unit === null ? [0, null, 0] : [(int) $this->t[$j]['num'], $unit['payloads'][0], 1 + $unit['len']];
        }
        $unit = $this->lex->matchAt($this->t, $j, ['tunit']);

        return $unit === null ? [0, null, 0] : [1, $unit['payloads'][0], $unit['len']];
    }

    private function rolling(int $n, string $unit, bool $forward): bool
    {
        $n = max(1, $n);
        $t = $this->today;
        if ($forward) {
            $end = match ($unit) {
                'day' => $t->addDays($n),
                'week' => $t->addWeeks($n),
                'month' => $t->addMonthsNoOverflow($n),
                default => $t->addYears($n),
            };
            if ($unit === 'day' && $n === 30) {
                return $this->add($this->dateField, 'between', ['next_30_days']);
            }

            return $this->addDate($t->toDateString(), $end->toDateString());
        }
        $start = match ($unit) {
            'day' => $t->subDays($n - 1),
            'week' => $t->subWeeks($n),
            'month' => $t->subMonthsNoOverflow($n),
            default => $t->subYears($n),
        };
        if ($unit === 'day' && $n === 90) {
            return $this->add($this->dateField, 'between', ['last_90_days']);
        }

        return $this->addDate($start->toDateString(), $t->toDateString());
    }

    // ---------------------------------------------------------------- report words

    private function groupAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['group']);
        if ($m === null) {
            return null;
        }
        $j = $i + $m['len'];
        $first = $this->groupField($j);
        if ($first === null) {
            return null;
        }
        $this->rows ??= $first[0];
        $j += $first[1];
        // "… and by status": a second grouping becomes the columns
        $k = $j;
        if (($sep = $this->lex->matchAt($this->t, $k, ['sep'])) !== null) {
            $k += $sep['len'];
        }
        if (($again = $this->lex->matchAt($this->t, $k, ['group'])) !== null) {
            $k += $again['len'];
        }
        $second = $this->groupField($k);
        if ($second !== null && $second[0] !== $this->rows) {
            $this->columns ??= $second[0];
            $j = $k + $second[1];
        }

        return $j - $i;
    }

    /** @return array{0: string, 1: int}|null field key and words used */
    private function groupField(int $j): ?array
    {
        $groupable = $this->dataset->groupable();
        $w = $this->lex->matchAt($this->t, $j, ['fieldword']);
        if ($w !== null) {
            $key = $this->fieldKey((string) $w['payloads'][0]);
            if ($key !== null && isset($groupable[$key])) {
                return [$key, $w['len']];
            }
        }
        $unit = $this->lex->matchAt($this->t, $j, ['tunit']);
        if ($unit !== null && $unit['payloads'][0] === 'year' && $this->dateField !== null && isset($groupable[$this->dateField])) {
            return [$this->dateField, $unit['len']];
        }

        return null;
    }

    private function measureAt(int $i): ?int
    {
        $m = $this->lex->matchAt($this->t, $i, ['measure']);
        if ($m === null) {
            return null;
        }
        $this->measure ??= (string) $m['payloads'][0];

        return $m['len'];
    }

    private function reportSpec(): ?array
    {
        if ($this->rows === null) {
            return null;
        }
        $measure = 'count';
        if (in_array($this->measure, ['sum', 'avg'], true)) {
            $field = null;
            $measurable = $this->dataset->measurable();
            foreach ($this->t as $i => $_) {
                $u = $this->lex->matchAt($this->t, $i, ['unit']);
                $p = $u['payloads'][0] ?? null;
                $field ??= match ($p) {
                    'project' => isset($measurable['projects_count']) ? 'projects_count' : null,
                    'amount', 'usd', 'syp', 'requested' => isset($measurable['amount']) ? 'amount' : null,
                    'loan' => isset($measurable['loan_amount']) ? 'loan_amount' : null,
                    default => null,
                };
            }
            $field ??= count($measurable) === 1 ? array_key_first($measurable) : null;
            if ($field !== null) {
                $measure = $this->measure.':'.$field;
            }
        }
        [$type, $field] = array_pad(explode(':', $measure), 2, null);
        $spec = new ReportSpec($this->rows, null, $this->columns, null, $type, $field, new QuerySpec(array_values($this->filters)));
        if ($spec->errors($this->dataset) !== []) {
            $spec = new ReportSpec($this->rows, null, null, null, 'count');
            if ($spec->errors($this->dataset) !== []) {
                return null;
            }
            $measure = 'count';
            $this->columns = null;
        }

        return ['rows' => $this->rows, 'columns' => $this->columns, 'measure' => $measure];
    }

    // ---------------------------------------------------------------- result

    private function finish(): ParseResult
    {
        $unknown = [];
        foreach ($this->t as $i => $token) {
            if ($token['used']) {
                continue;
            }
            $silent = false;
            $m = $this->lex->matchAt($this->t, $i, self::SILENT);
            if ($m !== null) {
                $silent = true;
            }
            if (($u = $this->lex->matchAt($this->t, $i, ['unit'])) !== null && ! in_array($u['payloads'][0], ['usd', 'syp'], true)) {
                $silent = true;
            }
            if (! $silent && ! in_array($token['raw'], $unknown, true)) {
                $unknown[] = $token['raw'];
            }
        }

        return new ParseResult($this->dataset->key(), array_values($this->filters), implode(', ', array_values(array_unique($this->search))), $unknown, $this->reportSpec());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Applications;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The agriculture and rehabilitation annexes of a project (M4.6), from the desktop Agriculture_Form and ApplicationRehab_Form:
 * every question needs an answer, a save replaces the project's answers, and the whole annex can be deleted. The rehabilitation annex
 * also keeps the property's ownership and area and sets the project's area and address after funding. Reading needs
 * `applications.view`, writing the application rights of M4.5 (the desktop let administrators and data entry write).
 */
final class ProjectAnnexService
{
    public function __construct(private ApplicationService $applications, private AccountAuditTrail $audit) {}

    /** "agriculture", "rehabilitation" or null, from the project's type name as the desktop decided. */
    public function kind(object $project): ?string
    {
        $type = (string) DB::table('project_types')->where('id', $project->project_type_id)->value('name_ar');
        foreach (['agriculture', 'rehabilitation'] as $kind) {
            if ($type !== '' && str_contains($type, config("annexes.$kind.type_marker"))) {
                return $kind;
            }
        }

        return null;
    }

    public function project(WebAccount $account, int $projectId): object
    {
        return $this->applications->project($account, $projectId);
    }

    public function canWrite(WebAccount $account, object $project): bool
    {
        return $this->applications->canWrite($account, (int) $project->city_id);
    }

    private function writable(WebAccount $account, int $projectId, string $kind): object
    {
        $project = $this->project($account, $projectId);
        if (! $this->canWrite($account, $project)) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $this->kind($project) === $kind || abort(404);

        return $project;
    }

    /**
     * The questions with their answers, in order, and whether each takes several answers.
     *
     * @return Collection<int, object{id: int, label: string, type: ?int, multiple: bool, answers: Collection}>
     */
    public function questions(string $kind): Collection
    {
        [$questions, $answers, $foreign] = $kind === 'agriculture' ? ['ag_questions', 'ag_answers', 'ag_question_id'] : ['rehab_questions', 'rehab_answers', 'rehab_question_id'];
        $en = app()->getLocale() === 'en';
        $multiple = config("annexes.$kind.multiple", []);
        $byQuestion = DB::table($answers)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->groupBy($foreign);

        return DB::table($questions)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($q) => (object) [
            'id' => (int) $q->id, 'label' => $en && $q->name_en ? $q->name_en : $q->name_ar, 'type' => isset($q->type) ? (int) $q->type : null,
            'multiple' => in_array(trim((string) $q->name_ar), $multiple, true),
            'answers' => ($byQuestion->get($q->id) ?? collect())->map(fn ($a) => (object) ['id' => (int) $a->id, 'label' => $en && $a->name_en ? $a->name_en : $a->name_ar])->values(),
        ]);
    }

    // ---------------------------------------------------------------- agriculture

    /** @return array<int, list<int>> question id => answer ids */
    public function agriculture(int $projectId): array
    {
        $out = [];
        foreach (DB::table('project_agriculture_answers')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            if ($row->ag_answer_id !== null) {
                $out[(int) $row->ag_question_id][] = (int) $row->ag_answer_id;
            }
        }

        return $out;
    }

    /** @param  array<int|string, list<int|string>|int|string>  $answers */
    public function saveAgriculture(WebAccount $account, int $projectId, array $answers): void
    {
        $project = $this->writable($account, $projectId, 'agriculture');
        $clean = $this->checkAnswers('agriculture', $answers);
        DB::transaction(function () use ($account, $project, $clean) {
            $now = now();
            DB::table('project_agriculture_answers')->where('project_id', $project->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            foreach ($clean as $question => $ids) {
                foreach ($ids as $answer) {
                    DB::table('project_agriculture_answers')->insert(['city_id' => $project->city_id, 'project_id' => $project->id, 'ag_question_id' => $question, 'ag_answer_id' => $answer, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $this->audit->record($account->id, 'applications.updated', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: (int) $project->id);
        });
    }

    // ---------------------------------------------------------------- rehabilitation

    /**
     * @return array{header: array{property_ownership: string, property_area: string, street_id: string, address_after_fund: string, created_by: ?string, edited_by: ?string},
     *               items: array<int, array{answers: list<int>, note: string, quantity: string, price: string, result: string}>, exists: bool}
     */
    public function rehabilitation(object $project): array
    {
        $rehab = DB::table('project_rehabilitations')->where('project_id', $project->id)->whereNull('deleted_at')->orderByDesc('id')->first();
        $items = [];
        if ($rehab) {
            foreach (DB::table('project_rehabilitation_items')->where('project_rehabilitation_id', $rehab->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
                $q = (int) $row->rehab_question_id;
                $items[$q] ??= ['answers' => [], 'note' => (string) $row->note, 'quantity' => $row->quantity === null ? '' : (string) $row->quantity,
                    'price' => $row->price === null ? '' : ApplicationService::plainNumber($row->price), 'result' => (string) $row->result];
                if ($row->rehab_answer_id !== null) {
                    $items[$q]['answers'][] = (int) $row->rehab_answer_id;
                }
            }
        }

        return [
            'header' => [
                'property_ownership' => (string) ($rehab->property_ownership ?? ''), 'property_area' => (string) ($rehab->property_area ?? ''),
                'street_id' => $project->street_id === null ? '' : (string) $project->street_id, 'address_after_fund' => (string) ($project->address_after_fund ?? ''),
                'created_by' => $rehab->created_by_name ?? null, 'edited_by' => $rehab->edited_by_name ?? null,
            ],
            'items' => $items, 'exists' => $rehab !== null,
        ];
    }

    /**
     * @param  array{property_ownership?: ?string, property_area?: ?string, street_id?: mixed, address_after_fund?: ?string}  $header
     * @param  array<int|string, array{answers?: list<int|string>|int|string, note?: ?string, quantity?: mixed, price?: mixed, result?: ?string}>  $items
     */
    public function saveRehabilitation(WebAccount $account, int $projectId, array $header, array $items): void
    {
        $project = $this->writable($account, $projectId, 'rehabilitation');
        $errors = [];
        $text = function (string $key, mixed $value, int $max) use (&$errors): ?string {
            $value = trim((string) $value);
            if (mb_strlen($value) > $max) {
                $errors[$key] = [__('people.errors.too_long', ['max' => $max])];
            }

            return $value === '' ? null : $value;
        };
        $values = [
            'property_ownership' => $text('header.property_ownership', $header['property_ownership'] ?? '', 100),
            'property_area' => $text('header.property_area', $header['property_area'] ?? '', 100),
        ];
        $address = $text('header.address_after_fund', $header['address_after_fund'] ?? '', 255);
        $street = ($header['street_id'] ?? '') === '' ? null : (int) $header['street_id'];
        if ($street !== null && ! DB::table('streets')->where('id', $street)->where('city_id', $project->city_id)->exists()) {
            $errors['header.street_id'] = [__('people.errors.choice')];
        }
        $answers = [];
        foreach ($items as $q => $item) {
            $answers[$q] = $item['answers'] ?? [];
        }
        try {
            $clean = $this->checkAnswers('rehabilitation', $answers);
        } catch (ValidationException $e) {
            $errors += $e->errors();
            $clean = [];
        }
        $details = [];
        foreach ($clean as $q => $_) {
            $item = $items[$q] ?? [];
            $quantity = trim((string) ($item['quantity'] ?? ''));
            $price = str_replace(',', '', trim((string) ($item['price'] ?? '')));
            if ($quantity !== '' && filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) === false) {
                $errors["items.$q.quantity"] = [__('people.errors.number')];
            }
            if ($price !== '' && (! is_numeric($price) || (float) $price < 0 || (float) $price >= 1e12)) {
                $errors["items.$q.price"] = [__('people.errors.number')];
            }
            $details[$q] = [
                'note' => $text("items.$q.note", $item['note'] ?? '', 5000), 'result' => $text("items.$q.result", $item['result'] ?? '', 5000),
                'quantity' => $quantity === '' ? null : (int) $quantity, 'price' => $price === '' ? null : round((float) $price, 2),
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($account, $project, $values, $address, $street, $clean, $details) {
            $now = now();
            $name = mb_substr((string) $account->username, 0, 100);
            DB::table('projects')->where('id', $project->id)->update(['street_id' => $street, 'address_after_fund' => $address, 'updated_at' => $now]);
            $rehab = DB::table('project_rehabilitations')->where('project_id', $project->id)->whereNull('deleted_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($rehab === null) {
                $rehabId = (int) DB::table('project_rehabilitations')->insertGetId($values + ['city_id' => $project->city_id, 'project_id' => $project->id, 'created_by_name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $rehabId = (int) $rehab->id;
                DB::table('project_rehabilitations')->where('id', $rehabId)->update($values + ['edited_by_name' => $name, 'updated_at' => $now]);
            }
            DB::table('project_rehabilitation_items')->where('project_rehabilitation_id', $rehabId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            // One row per ticked answer, each carrying the question's note, quantity, price and result (as the desktop stored them).
            foreach ($clean as $question => $ids) {
                foreach ($ids as $answer) {
                    DB::table('project_rehabilitation_items')->insert($details[$question] + [
                        'city_id' => $project->city_id, 'project_rehabilitation_id' => $rehabId, 'rehab_question_id' => $question, 'rehab_answer_id' => $answer, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            $this->audit->record($account->id, 'applications.updated', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: (int) $project->id);
        });
    }

    // ---------------------------------------------------------------- shared

    /** Removes the whole annex of a project (the desktop's "delete the annex"). */
    public function delete(WebAccount $account, int $projectId, string $kind): void
    {
        $project = $this->writable($account, $projectId, $kind);
        DB::transaction(function () use ($account, $project, $kind) {
            $now = now();
            if ($kind === 'agriculture') {
                DB::table('project_agriculture_answers')->where('project_id', $project->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            } else {
                $ids = DB::table('project_rehabilitations')->where('project_id', $project->id)->whereNull('deleted_at')->pluck('id');
                DB::table('project_rehabilitation_items')->whereIn('project_rehabilitation_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('project_rehabilitations')->whereIn('id', $ids)->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            $this->audit->record($account->id, 'applications.updated', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: (int) $project->id);
        });
    }

    /**
     * Every question answered, answers of their own question only, one answer for single-answer questions.
     *
     * @param  array<int|string, list<int|string>|int|string>  $answers
     * @return array<int, list<int>>
     */
    private function checkAnswers(string $kind, array $answers): array
    {
        $errors = [];
        $clean = [];
        $prefix = $kind === 'agriculture' ? 'answers' : 'items';
        foreach ($this->questions($kind) as $q) {
            $given = array_values(array_unique(array_filter(array_map('intval', (array) ($answers[$q->id] ?? $answers[(string) $q->id] ?? [])))));
            $allowed = $q->answers->pluck('id')->all();
            $key = $kind === 'agriculture' ? "$prefix.{$q->id}" : "$prefix.{$q->id}.answers";
            if ($given === []) {
                $errors[$key] = [__('annexes.errors.required')];
            } elseif (array_diff($given, $allowed) !== []) {
                $errors[$key] = [__('people.errors.choice')];
            } elseif (! $q->multiple && count($given) > 1) {
                $errors[$key] = [__('annexes.errors.single')];
            }
            $clean[$q->id] = $given;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }
}

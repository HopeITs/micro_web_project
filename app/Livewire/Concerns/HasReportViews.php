<?php

namespace App\Livewire\Concerns;

use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Reports\ReportFilterFields;
use Illuminate\Database\Eloquent\Builder;

/**
 * Saved views of the search and statistics pages in `saved_views` (schema v1.5, no change): a name over field keys, lookup ids, years,
 * dates, numbers and the page's own settings keys. The search text and anything typed about a person is never stored. A view applies only
 * what the viewer may see: cities and values are checked again each time, so a shared view shares the question, never the data. A saved
 * ready period ("this month") stays relative.
 *
 * Used together with HasReportFilters.
 */
trait HasReportViews
{
    public ?int $activeViewId = null;

    public ?string $viewNotice = null;

    public ?string $viewError = null;

    /** The `saved_views.dataset` of this page. */
    abstract protected function viewDataset(): string;

    /** The capability that lets an account share its views with everyone who opens this page. */
    abstract protected function viewShareCapability(): string;

    /** @return array<string, string|int|bool> the page's own settings (keys and numbers only) */
    abstract protected function viewSettings(): array;

    /** Restores the settings, checking each; returns false when something could not be restored. */
    abstract protected function applyViewSettings(array $settings): bool;

    public function saveView(?string $name = null): void
    {
        $account = $this->viewAccount();
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '' || mb_strlen($name) > 120) {
            $this->viewError = __('hc.view_name_invalid');

            return;
        }
        if ($this->ownViews()->where('name', $name)->exists()) {
            $this->viewError = __('hc.view_name_taken');

            return;
        }
        $view = SavedView::create([
            'account_id' => $account->id, 'dataset' => $this->viewDataset(), 'kind' => 'view', 'name' => $name,
            'spec' => ['filters' => $this->viewFilters(), 'view' => $this->viewSettings()], 'columns' => null, 'is_shared' => false, 'is_default' => false,
        ]);
        app(AccountAuditTrail::class)->record($account->id, 'saved_views.created', 'succeeded', decision: 'saved_view:'.$view->id,
            details: [['field' => 'name', 'after' => $name], ['field' => 'dataset', 'after' => $view->dataset]]);
        $this->activeViewId = $view->id;
        $this->viewError = null;
        $this->viewNotice = __('hc.view_saved', ['name' => $name]);
    }

    public function applyView(int $id): void
    {
        $view = $this->visibleViews()->firstWhere('id', $id);
        abort_if($view === null, 404);
        $spec = is_array($view->spec) ? $view->spec : [];
        $whole = $this->applyViewSettings(is_array($spec['view'] ?? null) ? $spec['view'] : []);

        $filters = [];
        $cities = [];
        $fields = ReportFilterFields::for($this->reportFilterKind());
        foreach (is_array($spec['filters'] ?? null) ? $spec['filters'] : [] as $item) {
            $field = is_array($item) && is_string($item['field'] ?? null) ? $item['field'] : '';
            $values = is_array($item['values'] ?? null) ? array_values(array_filter($item['values'], fn ($v) => $v === null || is_scalar($v))) : [];
            if ($field === 'city') {
                $cities = array_values(array_intersect(array_map('intval', $values), $this->permittedCities()));
                $whole = $whole && count($cities) === count($values);

                continue;
            }
            $type = $fields[$field][0] ?? null;
            if ($type === 'list') {
                $values = array_map('strval', $values);
                $known = array_values(array_intersect($values, $this->knownValues($field, $values)));
                $whole = $whole && count($known) === count($values);
                if ($known !== []) {
                    $filters[$field] = $known;
                    if (($item['op'] ?? '') === 'not_in') {
                        $filters['not'][] = $field;
                    }
                }
            } elseif ($type === 'choice') {
                $filters[$field] = (string) ($values[0] ?? '');
            } elseif ($type === 'date' || $type === 'amount') {
                $range = count($values) === 1 && $type === 'date' ? ReportFilterFields::preset((string) $values[0]) : $values;
                $filters[$field.'_from'] = (string) ($range[0] ?? '');
                $filters[$field.'_to'] = (string) ($range[1] ?? '');
            } else {
                $whole = false;
            }
        }
        $this->cities = $cities;
        $this->editing = null;
        $this->changed($filters);
        $this->activeViewId = $view->id;
        $this->viewError = null;
        $this->viewNotice = $whole ? null : __('hc.view_filters_dropped');
    }

    public function deleteView(int $id): void
    {
        $view = $this->ownView($id);
        $account = $this->viewAccount();
        $name = $view->name;
        $view->delete();
        app(AccountAuditTrail::class)->record($account->id, 'saved_views.deleted', 'succeeded', decision: 'saved_view:'.$id,
            details: [['field' => 'name', 'before' => $name], ['field' => 'dataset', 'before' => $this->viewDataset()]]);
        if ($this->activeViewId === $id) {
            $this->activeViewId = null;
        }
        $this->viewNotice = __('hc.view_deleted', ['name' => $name]);
    }

    public function toggleShareView(int $id): void
    {
        $view = $this->ownView($id);
        abort_unless($view->is_shared || $this->canShareViews(), 403); // un-sharing is always allowed
        $view->update(['is_shared' => ! $view->is_shared]);
    }

    private function canShareViews(): bool
    {
        return app(AccountAccess::class)->cities($this->viewAccount(), $this->viewShareCapability())->isNotEmpty();
    }

    /** @return list<array{field: string, op: string, values: list<mixed>}> the current cities and filters as a view stores them */
    private function viewFilters(): array
    {
        $out = [];
        $cities = array_values(array_intersect(array_map('intval', $this->cities), $this->permittedCities()));
        if ($cities !== []) {
            $out[] = ['field' => 'city', 'op' => 'in', 'values' => $cities];
        }
        $filters = $this->keptFilters();
        foreach (ReportFilterFields::for($this->reportFilterKind()) as $field => [$type]) {
            if (array_intersect_key($filters, array_flip(ReportFilterFields::keys($field))) === []) {
                continue;
            }
            if ($type === 'list') {
                $out[] = ['field' => $field, 'op' => in_array($field, $filters['not'] ?? [], true) ? 'not_in' : 'in', 'values' => $filters[$field]];
            } elseif ($type === 'choice') {
                $out[] = ['field' => $field, 'op' => 'in', 'values' => [$filters[$field]]];
            } else {
                $from = $filters[$field.'_from'] ?? '';
                $to = $filters[$field.'_to'] ?? '';
                $preset = $type === 'date' && $from !== '' && $to !== '' ? ReportFilterFields::presetOf($from, $to) : null;
                $out[] = ['field' => $field, 'op' => 'between', 'values' => $preset !== null ? [$preset] : [$from, $to]];
            }
        }

        return $out;
    }

    /** What <x-hc.report-views> draws. */
    protected function reportViewsBar(): array
    {
        $account = $this->viewAccount();
        $active = null;
        $list = $this->visibleViews()->map(function (SavedView $v) use ($account, &$active) {
            $item = ['id' => $v->id, 'name' => $v->name, 'own' => $v->account_id === $account->id, 'shared' => $v->is_shared,
                'owner' => (string) $v->account?->username, 'active' => $v->id === $this->activeViewId];
            if ($item['active']) {
                $active = $item;
            }

            return $item;
        })->all();

        return ['list' => $list, 'active' => $active, 'can_share' => $this->canShareViews(), 'notice' => $this->viewNotice, 'error' => $this->viewError];
    }

    private function visibleViews()
    {
        $account = $this->viewAccount();

        return SavedView::visibleTo($account->id, $this->viewDataset())->with('account:id,username')
            ->orderByRaw('CASE WHEN account_id = ? THEN 0 ELSE 1 END', [$account->id])->orderBy('name')->get();
    }

    private function viewAccount(): WebAccount
    {
        $account = auth()->user();
        abort_unless($account instanceof WebAccount, 403);

        return $account;
    }

    /** @return Builder<SavedView> */
    private function ownViews(): Builder
    {
        return SavedView::query()->where('account_id', $this->viewAccount()->id)->where('dataset', $this->viewDataset())->where('kind', 'view');
    }

    private function ownView(int $id): SavedView
    {
        $view = $this->ownViews()->whereKey($id)->first();
        abort_if($view === null, 403);

        return $view;
    }
}

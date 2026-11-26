{{-- A family-wide table grouped by member (name and relation). $tab, $tables (sub-table => rows), $members (the household). --}}
@php
    $locale = app()->getLocale();
    $show = function ($row, $field, $spec) {
        return match ($spec['kind']) {
            'lookup' => $row->{$field.'_label'} ?? null,
            'bool' => $row->{$field} === null ? null : ($row->{$field} ? __('people.yes') : __('people.no')),
            'decimal' => $row->{$field} === null ? null : number_format((float) $row->{$field}),
            'choice' => filled($row->{$field}) ? __('people.choices.'.$field.'.'.$row->{$field}) : null,
            default => filled($row->{$field}) ? $row->{$field} : null,
        };
    };
    $by = collect($tables)->map(fn ($rows) => $rows->groupBy('person_id'));
    // The work typed on the family row shows even when the person has no work record.
    $listed = $members->filter(fn ($m) => $by->contains(fn ($rows) => $rows->has($m->person_id)) || ($tab === 'work' && $m->working));
@endphp
@if($listed->isEmpty())
    <p class="muted fam-block">{{ __('families.no_records.'.$tab) }}</p>
@else
    <div class="fam-records">
    @foreach($listed as $m)
        <article wire:key="rec-{{ $tab }}-{{ $m->person_id }}">
            <h3><a href="{{ route('people.show', ['locale' => $locale, 'person' => $m->person_id]) }}">{{ $m->name }}</a>
                <span class="fam-role">{{ $m->is_head ? __('families.head') : ($m->relation ?? __('families.no_relation')) }}</span></h3>
            @if($tab === 'work' && $m->work_name)<p class="fam-block-note">{{ __('families.family_row_work') }} {{ $m->work_name }}</p>@endif
            @if($tab === 'work' && $m->working && $m->jobs->isEmpty())<p class="muted fam-block-note">{{ __('families.no_work_record') }}</p>@endif
            @foreach($tables as $kind => $rows)
                @php($own = $by[$kind]->get($m->person_id))
                @continue($own === null)
                @if(count($tables) > 1)<h4>{{ __('people.details.'.$kind) }}</h4>@endif
                <ul>
                @foreach($own as $row)
                    <li wire:key="rec-{{ $kind }}-{{ $row->id }}">
                        @foreach(\App\Services\People\PersonFields::DETAILS[$kind]['fields'] as $field => $spec)
                            @php($value = $show($row, $field, $spec))
                            @if($value !== null)<span><b>{{ __('people.fields.'.$field) }}:</b> {{ $value }}</span>@endif
                        @endforeach
                    </li>
                @endforeach
                </ul>
            @endforeach
        </article>
    @endforeach
    </div>
@endif

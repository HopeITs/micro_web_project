@php
    $en = app()->getLocale() === 'en';
    $dis = ! $canManage;
    $number = fn ($q) => $q->number;
@endphp
<div>
    @if($notice)<x-hc.alert type="success" style="margin-block-end:16px">{{ $notice }}</x-hc.alert>@endif
    @if($locked && $meetingId)
        <div class="hc-toolbar" style="margin-block-end:12px;gap:8px">
            <x-hc.button variant="secondary" size="sm" icon="file" :href="route('meetings.print', ['locale' => app()->getLocale(), 'meeting' => $meetingId, 'kind' => 'minutes'])" target="_blank">{{ __('paper_meeting.print_minutes') }}</x-hc.button>
            <x-hc.button variant="secondary" size="sm" icon="file" :href="route('meetings.print', ['locale' => app()->getLocale(), 'meeting' => $meetingId, 'kind' => 'survey'])" target="_blank">{{ __('paper_meeting.print_survey') }}</x-hc.button>
            <x-hc.button variant="secondary" size="sm" icon="file" :href="route('meetings.word', ['locale' => app()->getLocale(), 'meeting' => $meetingId, 'kind' => 'minutes'])">{{ __('paper_meeting.word_minutes') }}</x-hc.button>
            <x-hc.button variant="secondary" size="sm" icon="file" :href="route('meetings.word', ['locale' => app()->getLocale(), 'meeting' => $meetingId, 'kind' => 'survey'])">{{ __('paper_meeting.word_survey') }}</x-hc.button>
        </div>
    @endif
    @if($locked)<x-hc.alert type="info" style="margin-block-end:16px">{{ __('field.meeting.locked') }}
        @if($canReopen)<x-hc.button size="sm" variant="secondary" wire:click="reopen" wire:confirm="{{ __('field.meeting.reopen_confirm') }}">{{ __('field.meeting.reopen') }}</x-hc.button>@endif</x-hc.alert>@endif
    @if($errors->any())
        <x-hc.alert type="danger" style="margin-block-end:16px"><ul style="margin:0;padding-inline-start:20px">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-hc.alert>
    @endif

    <x-hc.card style="margin-block-end:16px">
        <h2 class="hc-h2">{{ __('field.meeting.header') }} · {{ $en ? $cityRow->name_en : $cityRow->name_ar }}
            @if($meetingId)<x-hc.badge>{{ $locked ? __('field.meeting.state_final') : __('field.meeting.state_draft') }}</x-hc.badge>@endif</h2>
        <div class="hc-field" style="margin-block-end:12px"><span class="hc-label">{{ __('field.meeting.career') }} *</span>
            @php($chosenIds = array_map('intval', $state['careerIds'] ?? []))
            <div class="hc-chips" role="group" aria-label="{{ __('field.meeting.career') }}" style="margin-block-end:8px">
                @foreach($careers->whereIn('id', $chosenIds) as $c)
                    <button type="button" class="hc-chip" @disabled($dis) wire:click="toggleCareer({{ $c->id }})" aria-pressed="true" title="{{ __('field.meeting.remove') }}">{{ $c->label }} <x-hc.icon name="x" :size="14"/></button>
                @endforeach
            </div>
            @if(! $dis)
            <select class="hc-select" aria-label="{{ __('field.meeting.add_career') }}" x-data x-on:change="if ($event.target.value) { $wire.toggleCareer(Number($event.target.value)); $event.target.value = '' }">
                <option value="">{{ __('field.meeting.add_career') }}</option>
                @foreach($careers->groupBy('sub') as $sub => $group)
                    <optgroup label="{{ $sub ?: '—' }}">@foreach($group as $c)@continue(in_array($c->id, $chosenIds, true))<option value="{{ $c->id }}">{{ $c->label }}</option>@endforeach</optgroup>
                @endforeach
            </select>
            @endif
        </div>
        <div class="hc-filters-grid" style="--cols:4">
            <div class="hc-field"><label class="hc-label" for="m-date">{{ __('field.meeting.date') }} *</label><input id="m-date" class="hc-input num ltr" type="date" wire:model="state.date" @disabled($dis)></div>
            <div class="hc-field grow"><label class="hc-label" for="m-place">{{ __('field.meeting.place') }}</label><input id="m-place" class="hc-input" maxlength="200" wire:model="state.place" @disabled($dis)></div>
            <div class="hc-field"><label class="hc-label" for="m-inv">{{ __('field.meeting.invited') }}</label><input id="m-inv" class="hc-input num ltr" inputmode="numeric" wire:model.live.debounce.300ms="state.invited" @disabled($dis)></div>
            <div class="hc-field"><label class="hc-label" for="m-att">{{ __('field.meeting.attendance') }}</label><input id="m-att" class="hc-input num ltr" inputmode="numeric" wire:model.live.debounce.300ms="state.attendance" @disabled($dis)></div>
        </div>
        @php($inv = (int) ($state['invited'] ?: 0))@php($att = (int) ($state['attendance'] ?: 0))
        <p class="muted" style="margin-block:8px 0">{{ __('field.meeting.absent') }}: <strong class="num">{{ $inv >= $att ? $inv - $att : '—' }}</strong> · {{ __('field.meeting.totals_hint') }}</p>
    </x-hc.card>

    @foreach($catalog['sections'] as $section)
    <x-hc.card style="margin-block-end:16px">
        <h2 class="hc-h2">{{ $section->label }}</h2>
        @foreach($section->questions as $q)
            <fieldset style="border:0;padding:0;margin:0 0 20px">
                <legend class="hc-label" style="font-weight:600">{{ $q->number }}. {{ $q->label }}</legend>
                @if($q->answer_type === 'option_number')
                    @foreach($q->options as $o)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center">
                            <input class="hc-input num ltr" style="width:6rem" inputmode="numeric" aria-label="{{ $en && $o->name_en ? $o->name_en : $o->name_ar }}" wire:model.live.debounce.300ms="state.opt.{{ $o->id }}.n" @disabled($dis)>
                            <span>{{ $en && $o->name_en ? $o->name_en : $o->name_ar }}</span>
                            @if($o->has_details || $o->is_other)<input class="hc-input" style="flex:1;min-width:10rem" maxlength="255" placeholder="{{ __('field.meeting.details') }}" wire:model="state.opt.{{ $o->id }}.text" @disabled($dis)>@endif
                        </div>
                    @endforeach
                    @php($sum = collect($q->options)->sum(fn ($o) => (int) ($state['opt'][$o->id]['n'] ?? 0)))
                    <p class="muted" style="margin:4px 0 0">{{ __('field.meeting.sum') }}: <strong class="num">{{ $sum }}</strong> / <span class="num">{{ $att }}</span></p>
                @elseif($q->answer_type === 'multi_option')
                    @foreach($q->options as $o)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center">
                            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" wire:model.live="state.opt.{{ $o->id }}.on" @disabled($dis) style="width:20px;height:20px"> {{ $en && $o->name_en ? $o->name_en : $o->name_ar }}</label>
                            @if(($o->has_details || $o->is_other) && ! empty($state['opt'][$o->id]['on']))<input class="hc-input" style="flex:1;min-width:10rem" maxlength="255" placeholder="{{ __('field.meeting.details') }}" wire:model="state.opt.{{ $o->id }}.text" @disabled($dis)>@endif
                        </div>
                    @endforeach
                @elseif($q->answer_type === 'text')
                    <textarea class="hc-input" rows="3" wire:model="state.tx.{{ $q->id }}" @disabled($dis)></textarea>
                @elseif($q->answer_type === 'competition')
                    @foreach($levels as $order => $level)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center">
                            <span style="min-width:9rem">{{ __('field.meeting.level.'.$order) }}</span>
                            <input class="hc-input num ltr" style="width:6rem" inputmode="numeric" aria-label="{{ __('field.meeting.count') }}" wire:model.live.debounce.300ms="state.comp.{{ $order }}.n" @disabled($dis)>
                            <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.reason') }}" wire:model="state.comp.{{ $order }}.reason" @disabled($dis)>
                        </div>
                    @endforeach
                    <p class="muted" style="margin:4px 0 0">{{ __('field.meeting.sum') }}: <strong class="num">{{ collect($state['comp'] ?? [])->sum(fn ($r) => (int) ($r['n'] ?? 0)) }}</strong> / <span class="num">{{ $att }}</span></p>
                @elseif($q->answer_type === 'season_table')
                    @foreach($state['seasons'] ?? [] as $i => $row)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center;flex-wrap:wrap" wire:key="season-{{ $i }}">
                            <input class="hc-input num ltr" style="width:6rem" inputmode="numeric" aria-label="{{ __('field.meeting.count') }}" wire:model.live.debounce.300ms="state.seasons.{{ $i }}.n" @disabled($dis)>
                            <input class="hc-input" style="width:9rem" placeholder="{{ __('field.meeting.from') }}" wire:model="state.seasons.{{ $i }}.from" @disabled($dis)>
                            <input class="hc-input" style="width:9rem" placeholder="{{ __('field.meeting.to') }}" wire:model="state.seasons.{{ $i }}.to" @disabled($dis)>
                            <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.reason') }}" wire:model="state.seasons.{{ $i }}.reason" @disabled($dis)>
                            @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="x" wire:click="removeRow('seasons', {{ $i }})" aria-label="{{ __('field.meeting.remove') }}"/>@endif
                        </div>
                    @endforeach
                    @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addRow('seasons')">{{ __('field.meeting.add_row') }}</x-hc.button>@endif
                    <p class="muted" style="margin:4px 0 0">{{ __('field.meeting.sum') }}: <strong class="num">{{ collect($state['seasons'] ?? [])->sum(fn ($r) => (int) ($r['n'] ?? 0)) }}</strong> / <span class="num">{{ $att }}</span></p>
                @elseif($q->answer_type === 'brand_table')
                    <textarea class="hc-input" rows="4" wire:model="state.brands" placeholder="{{ __('field.meeting.brands_hint') }}" @disabled($dis)></textarea>
                @elseif($q->answer_type === 'equipment_table')
                    @foreach($state['equipment'] ?? [] as $i => $row)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center" wire:key="equip-{{ $i }}">
                            <input class="hc-input" style="width:12rem" placeholder="{{ __('field.meeting.spec') }}" wire:model="state.equipment.{{ $i }}.spec" @disabled($dis)>
                            <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.items') }}" wire:model="state.equipment.{{ $i }}.items" @disabled($dis)>
                            @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="x" wire:click="removeRow('equipment', {{ $i }})" aria-label="{{ __('field.meeting.remove') }}"/>@endif
                        </div>
                    @endforeach
                    @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addRow('equipment')">{{ __('field.meeting.add_row') }}</x-hc.button>@endif
                @elseif($q->answer_type === 'challenge_table')
                    @foreach($state['challenges'] ?? [] as $i => $row)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center;flex-wrap:wrap" wire:key="chal-{{ $i }}">
                            <input class="hc-input" style="width:14rem" placeholder="{{ __('field.meeting.challenge') }}" wire:model="state.challenges.{{ $i }}.name" @disabled($dis)>
                            <input class="hc-input num ltr" style="width:6rem" inputmode="numeric" aria-label="{{ __('field.meeting.count') }}" wire:model.live.debounce.300ms="state.challenges.{{ $i }}.n" @disabled($dis)>
                            <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.solution') }}" wire:model="state.challenges.{{ $i }}.solution" @disabled($dis)>
                            @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="x" wire:click="removeRow('challenges', {{ $i }})" aria-label="{{ __('field.meeting.remove') }}"/>@endif
                        </div>
                    @endforeach
                    @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addRow('challenges')">{{ __('field.meeting.add_row') }}</x-hc.button>@endif
                    <p class="muted" style="margin:4px 0 0">{{ __('field.meeting.sum') }}: <strong class="num">{{ collect($state['challenges'] ?? [])->sum(fn ($r) => (int) ($r['n'] ?? 0)) }}</strong> / <span class="num">{{ $att }}</span></p>
                @elseif($q->answer_type === 'participant_table')
                    @foreach($state['part'][$q->id] ?? [] as $i => $row)
                        <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center;flex-wrap:wrap" wire:key="part-{{ $q->id }}-{{ $i }}">
                            <input class="hc-input ltr" style="width:9rem" list="meeting-projects" placeholder="{{ __('field.meeting.project') }}" wire:model="state.part.{{ $q->id }}.{{ $i }}.project" @disabled($dis)>
                            <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.name') }}" wire:model="state.part.{{ $q->id }}.{{ $i }}.name" @disabled($dis)>
                            <input class="hc-input" style="width:11rem" placeholder="{{ __('field.meeting.speciality') }}" wire:model="state.part.{{ $q->id }}.{{ $i }}.speciality" @disabled($dis)>
                            @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="x" wire:click="removeRow('part', {{ $i }}, {{ $q->id }})" aria-label="{{ __('field.meeting.remove') }}"/>@endif
                        </div>
                    @endforeach
                    @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addRow('part', {{ $q->id }})">{{ __('field.meeting.add_row') }}</x-hc.button>@endif
                @endif
            </fieldset>
        @endforeach
        <div class="hc-field"><label class="hc-label" for="min-{{ $section->id }}">{{ __('field.meeting.minutes') }} — {{ $section->label }}</label>
            <textarea id="min-{{ $section->id }}" class="hc-input" rows="3" wire:model="state.minutes.{{ $section->id }}" @disabled($dis)></textarea></div>
    </x-hc.card>
    @endforeach

    <x-hc.card style="margin-block-end:16px">
        <h2 class="hc-h2">{{ __('field.meeting.attendees') }}</h2>
        <p class="muted">{{ __('field.meeting.attendees_hint') }} <strong class="num">{{ count($state['attendees'] ?? []) }}</strong> / <span class="num">{{ $att }}</span></p>
        @foreach($state['attendees'] ?? [] as $i => $row)
            <div class="hc-toolbar" style="padding:4px 0;gap:8px;align-items:center;flex-wrap:wrap" wire:key="att-{{ $i }}">
                <input class="hc-input ltr" style="width:9rem" list="meeting-projects" placeholder="{{ __('field.meeting.project') }}" wire:model="state.attendees.{{ $i }}.project" @disabled($dis)>
                <input class="hc-input" style="flex:1;min-width:10rem" placeholder="{{ __('field.meeting.name') }}" wire:model="state.attendees.{{ $i }}.name" @disabled($dis)>
                <input class="hc-input" style="width:11rem" placeholder="{{ __('field.meeting.speciality') }}" wire:model="state.attendees.{{ $i }}.speciality" @disabled($dis)>
                @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="x" wire:click="removeRow('attendees', {{ $i }})" aria-label="{{ __('field.meeting.remove') }}"/>@endif
            </div>
        @endforeach
        @if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addRow('attendees')">{{ __('field.meeting.add_row') }}</x-hc.button>@endif
        <div class="hc-field" style="margin-block-start:16px"><label class="hc-label" for="m-notes">{{ __('field.meeting.notes') }}</label><textarea id="m-notes" class="hc-input" rows="3" wire:model="state.notes" @disabled($dis)></textarea></div>
    </x-hc.card>

    <datalist id="meeting-projects">@foreach($beneficiaries as $b)<option value="{{ $b->project }}">{{ $b->name }}</option>@endforeach</datalist>
    <x-hc.card style="margin-block-end:16px">
        <h2 class="hc-h2">{{ __('field.meeting.eligibility') }}</h2>
        <p class="muted">{{ __('field.meeting.eligibility_hint') }}</p>
        @if($beneficiaries->isEmpty())
            <p>{{ empty($state['careerIds']) ? __('field.meeting.eligibility_pick') : __('field.meeting.eligibility_none') }}</p>
        @else
            <div class="hc-table-wrap"><table class="hc-table">
                <thead><tr><th>{{ __('field.meeting.project') }}</th><th>{{ __('field.meeting.name') }}</th><th>{{ __('staff.tasks.state') }}</th><th>{{ __('field.meeting.funded') }}</th><th></th></tr></thead>
                <tbody>@foreach($beneficiaries as $b)
                    <tr wire:key="ben-{{ $b->project }}"><td class="num ltr">{{ $b->project }}</td><td>{{ $b->name ?: '—' }}</td><td>{{ $b->state }}</td><td>{{ $b->funded ? __('field.meeting.yes') : __('field.meeting.no') }}</td>
                        <td>@if(! $dis)<x-hc.button type="button" variant="ghost" size="sm" icon="plus" wire:click="addAttendee(@js($b->project), @js($b->name))">{{ __('field.meeting.add_attendee') }}</x-hc.button>@endif</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </x-hc.card>

    @if($canManage || $canDelete)
    <div class="hc-action-bar" style="display:flex;gap:8px;flex-wrap:wrap;position:sticky;inset-block-end:0;padding:12px 0;background:var(--surface,transparent)">
        @if($canManage)
            <x-hc.button type="button" variant="secondary" icon="check" wire:click="saveDraft" wire:loading.attr="disabled">{{ __('field.meeting.save_draft') }}</x-hc.button>
            <x-hc.button type="button" icon="check" wire:click="saveFinal" wire:confirm="{{ __('field.meeting.final_confirm') }}" wire:loading.attr="disabled">{{ __('field.meeting.save_final') }}</x-hc.button>
        @endif
        @if($canDelete)<x-hc.button type="button" variant="ghost" icon="x" wire:click="delete" wire:confirm="{{ __('field.meeting.delete_confirm') }}" style="margin-inline-start:auto">{{ __('field.meeting.delete') }}</x-hc.button>@endif
    </div>
    @endif
</div>

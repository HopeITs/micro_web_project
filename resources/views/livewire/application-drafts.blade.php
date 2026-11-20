<div>
    @if($drafts !== [])
    @php($locale = app()->getLocale())
    <x-hc.card :title="__('applications.drafts.title')" :subtitle="__('applications.drafts.intro', ['days' => \App\Services\Drafts\ApplicationDraftStore::RETENTION_DAYS])" :flush="true" style="margin-block-end:16px">
        <div class="hc-table-wrap"><table class="hc-table stack">
            <thead><tr><th>{{ __('applications.number') }}</th><th>{{ __('hc.beneficiary') }}</th><th>{{ __('applications.fields.name') }}</th><th>{{ __('applications.drafts.saved') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($drafts as $d)
                <tr wire:key="draft-{{ $d['id'] }}">
                    <td data-label="{{ __('applications.number') }}" class="ltr">{{ ($codes[$d['city']] ?? '').'-'.$d['number'] }}@if($d['project_id']) <x-hc.badge>{{ __('applications.drafts.edit_of_saved') }}</x-hc.badge>@endif</td>
                    <td data-label="{{ __('hc.beneficiary') }}">{{ $d['person'] ?: '—' }}</td>
                    <td data-label="{{ __('applications.fields.name') }}">{{ \Illuminate\Support\Str::limit($d['name'], 60) ?: '—' }}</td>
                    <td data-label="{{ __('applications.drafts.saved') }}" class="num">{{ str_replace('T', ' ', substr($d['saved_at'], 0, 16)) }}</td>
                    <td style="white-space:nowrap">
                        <x-hc.button size="sm" :href="$d['project_id'] ? route('applications.edit', ['locale' => $locale, 'project' => $d['project_id']]) : route('applications.create', ['locale' => $locale, 'draft' => $d['id']])">{{ __('applications.drafts.resume') }}</x-hc.button>
                        <x-hc.button size="sm" variant="ghost" wire:click="discard('{{ $d['id'] }}')" wire:confirm="{{ __('applications.drafts.confirm_discard') }}">{{ __('applications.drafts.discard') }}</x-hc.button>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </x-hc.card>
    @endif
</div>

@php
    $locale = app()->getLocale();
    $en = $locale === 'en';
    $S = fn ($key) => __('papers.settings.'.$key);
    $row = $edit ? $signatories->firstWhere('id', $edit) : null;
    $val = fn ($field) => old($field, $row->{$field} ?? '');
@endphp
<x-layouts.hc :title="$S('title')">
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('papers.title') }}</p><h1>{{ $S('title') }}</h1><p>{{ $S('intro') }}</p></div>
        <x-hc.button variant="ghost" :href="route('papers.index', ['locale' => $locale])">{{ __('papers.back') }}</x-hc.button>
    </div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:16px">{{ session('status') }}</x-hc.alert>@endif
    @if($errors->any())<x-hc.alert type="danger" style="margin-block-end:16px">{{ $errors->first() }}</x-hc.alert>@endif

    <x-hc.card>
        <form class="hc-toolbar" method="GET" action="{{ route('papers.settings', ['locale' => $locale]) }}">
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('papers.city') }}</label>
                <select id="city" name="city" class="hc-select" onchange="this.form.submit()">
                    @foreach($cities as $c)<option value="{{ $c->id }}" @selected($c->id === $city)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach
                </select>
            </div>
        </form>
    </x-hc.card>

    <form method="POST" action="{{ route('papers.settings.save', ['locale' => $locale]) }}" class="hc-grid" style="margin-block-start:16px">
        @csrf
        <input type="hidden" name="city" value="{{ $city }}">
        <x-hc.card :title="$S('footer_title')">
            <div class="hc-form-grid">
                @foreach(['address_ar', 'address_en', 'phone_1', 'phone_2', 'complaints_phone', 'office_days', 'office_hours'] as $f)
                    <x-hc.field :label="$S('f.'.$f)" :for="'s-'.$f" :error="$errors->first($f)">
                        <input class="hc-input {{ str_starts_with($f, 'phone') || $f === 'complaints_phone' || $f === 'address_en' ? 'ltr' : '' }}" id="s-{{ $f }}" name="{{ $f }}" maxlength="255" value="{{ old($f, $settings[$f]) }}">
                    </x-hc.field>
                @endforeach
                <x-hc.field :label="$S('f.notes')" for="s-notes" :error="$errors->first('notes')">
                    <textarea class="hc-input" id="s-notes" name="notes" rows="2" maxlength="2000">{{ old('notes', $settings['notes']) }}</textarea>
                </x-hc.field>
            </div>
            <div style="margin-block-start:12px"><x-hc.button type="submit" icon="check">{{ $S('save') }}</x-hc.button></div>
        </x-hc.card>
    </form>

    <x-hc.card :title="$S('signatories')" :flush="true" style="margin-block-start:16px">
        <div class="hc-table-wrap">
            <table class="hc-table stack">
                <thead><tr><th>{{ $S('role') }}</th><th>{{ $S('f.full_name') }}</th><th>{{ $S('valid') }}</th><th></th></tr></thead>
                <tbody>
                @forelse($signatories as $s)
                    <tr>
                        <td data-label="{{ $S('role') }}">{{ $S('roles.'.$s->role) }}</td>
                        <td data-label="{{ $S('f.full_name') }}">{{ $s->full_name }}</td>
                        <td data-label="{{ $S('valid') }}" class="num">{{ $s->valid_from ?: '…' }} → {{ $s->valid_to ?: '…' }}</td>
                        <td>
                            <a class="row-link" href="{{ route('papers.settings', ['locale' => $locale, 'city' => $city, 'signatory' => $s->id]) }}#signatory">{{ $S('edit') }}</a>
                            <form method="POST" action="{{ route('papers.settings.signatory.remove', ['locale' => $locale]) }}" style="display:inline" onsubmit="return confirm(@js($S('confirm_remove')))">
                                @csrf @method('DELETE')
                                <input type="hidden" name="city" value="{{ $city }}"><input type="hidden" name="id" value="{{ $s->id }}">
                                <button class="row-link" type="submit" style="background:none;border:0;cursor:pointer">{{ $S('remove') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">{{ $S('none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-hc.card>

    <form method="POST" action="{{ route('papers.settings.signatory', ['locale' => $locale]) }}" id="signatory" style="margin-block-start:16px">
        @csrf
        <input type="hidden" name="city" value="{{ $city }}">
        @if($row)<input type="hidden" name="id" value="{{ $row->id }}">@endif
        <x-hc.card :title="$row ? $S('edit_signatory') : $S('new_signatory')">
            <div class="hc-form-grid">
                <x-hc.field :label="$S('role')" for="g-role" :error="$errors->first('role')" required>
                    <select class="hc-select" id="g-role" name="role">
                        @foreach(\App\Services\Papers\PaperSettings::ROLES as $r)<option value="{{ $r }}" @selected($val('role') === $r)>{{ $S('roles.'.$r) }}</option>@endforeach
                    </select>
                </x-hc.field>
                @foreach(['full_name', 'father_name', 'mother_name', 'registration_place', 'national_number', 'id_issue_text'] as $f)
                    <x-hc.field :label="$S('f.'.$f)" :for="'g-'.$f" :error="$errors->first($f)" :required="$f === 'full_name'">
                        <input class="hc-input {{ $f === 'national_number' ? 'num ltr' : '' }}" id="g-{{ $f }}" name="{{ $f }}" maxlength="200" value="{{ $val($f) }}">
                    </x-hc.field>
                @endforeach
                @foreach(['birth_date', 'valid_from', 'valid_to'] as $f)
                    <x-hc.field :label="$S('f.'.$f)" :for="'g-'.$f" :error="$errors->first($f)">
                        <input class="hc-input num ltr" type="date" id="g-{{ $f }}" name="{{ $f }}" value="{{ $val($f) }}">
                    </x-hc.field>
                @endforeach
            </div>
            <div style="margin-block-start:12px;display:flex;gap:8px">
                <x-hc.button type="submit" icon="check">{{ $S('save_signatory') }}</x-hc.button>
                @if($row)<x-hc.button variant="ghost" :href="route('papers.settings', ['locale' => $locale, 'city' => $city])">{{ $S('cancel') }}</x-hc.button>@endif
            </div>
        </x-hc.card>
    </form>
</x-layouts.hc>

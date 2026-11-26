<div>
@if($visible)
    @php
        $en = app()->getLocale() === 'en';
        $locale = app()->getLocale();
        $name = fn ($t) => $en && $t->name_en ? $t->name_en : $t->name_ar;
        $kinds = implode(', ', array_keys(config('attachments.upload.types')));
        $maxMb = (int) config('attachments.upload.max_kb') / 1024;
        $size = fn (?int $b) => $b === null ? '—' : ($b >= 1048576 ? number_format($b / 1048576, 1).' MB' : max(1, (int) round($b / 1024)).' KB');
    @endphp
    <x-hc.card :title="__('attachments.panel')" :subtitle="$counted ? trans_choice('attachments.count', $total, ['count' => $total]) : null" :flush="true" id="attachments">
        @if($message !== '')<div style="padding:12px 20px 0"><x-hc.alert type="success">{{ $message }}</x-hc.alert></div>@endif

        @if($showPhoto)
        <div style="display:flex;gap:16px;align-items:center;padding:16px 20px;border-block-end:1px solid var(--line, #e5e7eb);flex-wrap:wrap">
            @if($photoRow && $photoRow->available)
                <a href="{{ route('attachments.file', ['locale' => $locale, 'attachment' => $photoRow->id]) }}" target="_blank" rel="noopener">
                    <img src="{{ route('attachments.file', ['locale' => $locale, 'attachment' => $photoRow->id]) }}" alt="{{ __('attachments.photo') }}" width="88" height="88" style="width:88px;height:88px;object-fit:cover;border-radius:12px;border:1px solid var(--line, #e5e7eb)">
                </a>
            @else
                <div aria-hidden="true" style="width:88px;height:88px;border-radius:12px;display:grid;place-items:center;background:var(--surface-2, #f3f4f6);color:var(--muted)"><x-hc.icon name="user" :size="40"/></div>
            @endif
            <div style="display:grid;gap:6px">
                <strong>{{ __('attachments.photo') }}</strong>
                @if(! $photoRow)<span class="muted">{{ __('attachments.no_photo') }}</span>
                @elseif(! $photoRow->available)<x-hc.badge tone="warn">{{ __('attachments.not_moved') }}</x-hc.badge>@endif
                @if($canPhoto)
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <label class="hc-btn hc-btn-secondary hc-btn-sm" style="cursor:pointer;position:relative">
                        <x-hc.icon name="plus" :size="18"/>{{ $photoRow ? __('attachments.photo_change') : __('attachments.photo_add') }}
                        <input type="file" wire:model="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" style="position:absolute;width:1px;height:1px;opacity:0" aria-label="{{ __('attachments.photo') }}">
                    </label>
                    @if($photoRow)
                        <x-hc.button size="sm" variant="ghost" icon="x" wire:click="delete({{ $photoRow->id }})" wire:confirm="{{ __('attachments.confirm_photo_delete') }}" style="color:var(--danger, #b42318)">{{ __('attachments.photo_delete') }}</x-hc.button>
                    @endif
                    <span class="muted" style="font-size:.85rem" wire:loading.remove wire:target="photo">{{ __('attachments.photo_hint', ['max' => $maxMb]) }}</span>
                    <span class="muted" style="font-size:.85rem" wire:loading wire:target="photo">{{ __('attachments.uploading') }}</span>
                </div>
                @endif
                @error('photo')<p class="hc-error" role="alert">{{ $message }}</p>@enderror
            </div>
        </div>
        @endif

        @if($total > 0)
        <div class="hc-toolbar" style="padding:12px 20px 0">
            <div class="hc-chips" role="group" aria-label="{{ __('attachments.type') }}">
                <button type="button" class="hc-chip" wire:click="filter(null)" aria-pressed="{{ $type === null ? 'true' : 'false' }}">{{ __('attachments.all') }} <span class="muted num">({{ $total }})</span></button>
                @foreach($chips as $t)
                    <button type="button" class="hc-chip" wire:key="chip-{{ $t->id }}" wire:click="filter({{ $t->id }})" aria-pressed="{{ $type === (int) $t->id ? 'true' : 'false' }}">{{ $name($t) }} <span class="muted num">({{ $counts[$t->id] ?? 0 }})</span></button>
                @endforeach
                @if($uncategorised)
                    <button type="button" class="hc-chip" wire:click="filter(0)" aria-pressed="{{ $type === 0 ? 'true' : 'false' }}">{{ __('attachments.uncategorised') }} <span class="muted num">({{ $uncategorised }})</span></button>
                @endif
            </div>
        </div>
        @endif

        <div class="hc-table-wrap" wire:loading.class="opacity-60" wire:target="filter,delete">
            @if($rows->isEmpty())
                <x-hc.empty :title="__('attachments.empty')" icon="file">{{ $types->isNotEmpty() ? __('attachments.empty_hint') : __('attachments.empty_readonly') }}</x-hc.empty>
            @else
            <table class="hc-table stack">
                <thead><tr><th>{{ __('attachments.name') }}</th><th>{{ __('attachments.type') }}</th><th>{{ __('attachments.uploaded') }}</th><th>{{ __('attachments.size') }}</th><th></th></tr></thead>
                <tbody>
                @foreach($rows as $r)
                    @php($url = route('attachments.file', ['locale' => $locale, 'attachment' => $r->id]))
                    <tr wire:key="att-{{ $r->id }}">
                        <td data-label="{{ __('attachments.name') }}">
                            <div style="display:flex;gap:10px;align-items:center;min-width:0">
                                @if($r->available && $r->is_image)
                                    <a href="{{ $url }}" target="_blank" rel="noopener" style="flex:none"><img src="{{ $url }}" alt="" width="44" height="44" loading="lazy" style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid var(--line, #e5e7eb)"></a>
                                @else
                                    <span style="flex:none;width:44px;height:44px;display:grid;place-items:center;border-radius:8px;background:var(--surface-2, #f3f4f6);color:var(--muted)"><x-hc.icon name="file" :size="22"/></span>
                                @endif
                                <span style="overflow-wrap:anywhere">
                                    @if($r->available)<a class="row-link" href="{{ $url }}" target="_blank" rel="noopener" dir="auto">{{ $r->original_name ?: '#'.$r->id }}</a>
                                    @else<span dir="auto">{{ $r->original_name ?: '#'.$r->id }}</span><br><x-hc.badge tone="warn" title="{{ __('attachments.not_moved_hint') }}">{{ __('attachments.not_moved') }}</x-hc.badge>@endif
                                </span>
                            </div>
                        </td>
                        <td data-label="{{ __('attachments.type') }}">{{ $r->type_label ?? __('attachments.uncategorised') }}</td>
                        <td data-label="{{ __('attachments.uploaded') }}"><span class="num">{{ $r->uploaded_at ? substr((string) $r->uploaded_at, 0, 10) : '—' }}</span>@if($r->uploader) <span class="muted">· {{ $r->uploader }}</span>@endif</td>
                        <td data-label="{{ __('attachments.size') }}" class="num"><span dir="ltr">{{ $size($r->size) }}</span></td>
                        <td class="full hc-attach-ops">
                            @if($identityPerson && $r->available && in_array(strtolower((string) $r->extension), \App\Services\Identity\IdentityReviewService::READABLE, true))<x-hc.button size="sm" variant="ghost" icon="id" :href="route('people.identity', ['locale' => $locale, 'person' => $identityPerson, 'attachment' => $r->id])">{{ __('identity.attach_button') }}</x-hc.button>@endif
                            @if($r->available)<x-hc.button size="sm" variant="ghost" icon="install" :href="$url.'?download=1'">{{ __('attachments.download') }}</x-hc.button>@endif
                            @if($types->isNotEmpty())
                                <x-hc.button size="sm" variant="ghost" icon="x" wire:click="delete({{ $r->id }})" wire:confirm="{{ __('attachments.confirm_delete') }}" style="color:var(--danger, #b42318)">{{ __('attachments.delete') }}</x-hc.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>
        @if($hidden)<p class="muted" style="padding:8px 20px;font-size:.85rem">{{ trans_choice('attachments.hidden_note', $hidden, ['count' => $hidden]) }}</p>@endif

        @if($types->isNotEmpty())
        <x-slot:footer>
            @php($filesError = $errors->first('files') ?: $errors->first('files.*'))
            <form wire:submit="saveFiles" class="hc-attach" x-data="attachDrop" x-on:dragover.prevent x-on:drop.prevent
                x-on:livewire-upload-start="start()"x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire-upload-finish="stop()" x-on:livewire-upload-error="stop()" x-on:livewire-upload-cancel="stop()">
                <h3>{{ __('attachments.add') }}</h3>
                <div class="hc-attach-grid">
                    <x-hc.field :label="__('attachments.type')" for="att-type-{{ $owner }}" :error="$errors->first('uploadType')" :required="true">
                        <select id="att-type-{{ $owner }}" class="hc-select" wire:model="uploadType" aria-invalid="{{ $errors->has('uploadType') ? 'true' : 'false' }}">
                            <option value="">{{ __('attachments.choose_type') }}</option>
                            @foreach($types->groupBy('category') as $category => $group)
                                <optgroup label="{{ __('attachments.categories.'.$category) }}">
                                    @foreach($group as $t)<option value="{{ $t->id }}">{{ $name($t) }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </x-hc.field>
                    <div class="hc-field">
                        <span class="hc-label">{{ __('attachments.files') }}<span class="req" aria-hidden="true">*</span></span>
                        <div @class(['hc-drop', 'is-invalid' => $filesError !== '']) :class="{ 'is-over': over }"
                            @dragenter.prevent="over = true" @dragover.prevent="over = true" @dragleave="leave($event)" @drop.prevent="drop($event)">
                            <x-hc.icon name="upload" :size="26"/>
                            <p class="hc-drop-text" x-text="over ? $el.dataset.over : $el.dataset.idle" data-idle="{{ __('attachments.drop') }}" data-over="{{ __('attachments.drop_now') }}">{{ __('attachments.drop') }}</p>
                            <label class="hc-btn hc-btn-secondary hc-btn-sm hc-drop-button">
                                <x-hc.icon name="plus" :size="16"/>{{ __('attachments.choose_files') }}
                                <input id="att-files-{{ $owner }}" x-ref="input" class="hc-drop-input" type="file" multiple wire:model="files"
                                    accept="{{ collect(array_keys(config('attachments.upload.types')))->map(fn ($k) => '.'.$k)->implode(',') }}"
                                    aria-describedby="att-files-{{ $owner }}-hint att-files-{{ $owner }}-error" aria-invalid="{{ $filesError !== '' ? 'true' : 'false' }}">
                            </label>
                            {{-- The kinds are Latin: isolated so their order survives a right-to-left sentence. --}}
                            <p class="hc-hint" id="att-files-{{ $owner }}-hint">{{ __('attachments.hint', ['kinds' => "\u{2066}".$kinds."\u{2069}", 'max' => $maxMb, 'files' => config('attachments.upload.max_files')]) }}</p>
                        </div>
                        <div class="hc-drop-progress" x-show="uploading" x-cloak role="status">
                            <div class="hc-progress" role="progressbar" aria-label="{{ __('attachments.upload_progress') }}" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="progress"><span :style="'width: ' + progress + '%'"></span></div>
                            <span>{{ __('attachments.uploading') }} <span class="num" dir="ltr" x-text="progress + '%'"></span></span>
                        </div>
                        @if($filesError !== '')<p class="hc-error" id="att-files-{{ $owner }}-error" role="alert">{{ $filesError }}</p>@endif
                    </div>
                </div>
                @if($files)
                    <div class="hc-picked">
                        <p class="hc-label">{{ __('attachments.selected') }} <span class="num">({{ count($files) }})</span></p>
                        <ul aria-label="{{ __('attachments.selected') }}">
                            @foreach($files as $i => $f)
                                {{-- Inline form only: a block php directive after the inline ones above would swallow the markup between. --}}
                                @php($upload = $f instanceof \Illuminate\Http\UploadedFile)
                                @php($picked = $upload ? $f->getClientOriginalName() : '')
                                @php($bytes = $upload ? rescue(fn () => (int) $f->getSize(), null, false) : null)
                                <li wire:key="pick-{{ $i }}-{{ md5($picked) }}">
                                    <x-hc.icon name="file" :size="18"/>
                                    <span class="hc-picked-name" dir="auto">{{ $picked }}</span>
                                    <span class="muted num" dir="ltr">{{ $size($bytes) }}</span>
                                    <button type="button" class="hc-picked-remove" wire:click="removeFile({{ $i }})" aria-label="{{ __('attachments.remove', ['name' => $picked]) }}" title="{{ __('attachments.remove', ['name' => $picked]) }}"><x-hc.icon name="x" :size="16"/></button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="hc-attach-actions">
                    <x-hc.button type="submit" icon="upload" wire:loading.attr="disabled" wire:target="files,saveFiles">
                        <span wire:loading.remove wire:target="saveFiles">{{ __('attachments.upload') }}</span><span wire:loading wire:target="saveFiles">{{ __('attachments.uploading') }}</span>
                    </x-hc.button>
                </div>
            </form>
        </x-slot:footer>
        @endif
    </x-hc.card>
@endif
</div>

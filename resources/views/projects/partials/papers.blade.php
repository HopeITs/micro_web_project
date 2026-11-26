{{-- The papers hub: every printable paper and its data-entry page, grouped, searchable; "recently used" stays in this browser (paper kinds only). --}}
<section class="hc-card hc-pj-papers" x-data="papersHub" aria-labelledby="pj-papers-title">
    <header class="hc-pj-papers-head">
        <h2 id="pj-papers-title">{{ __('project_page.papers.title') }}</h2>
        <div class="hc-input-icon">
            <input type="search" class="hc-input" x-model="query" @keydown.enter.prevent="openFirst()" @keydown.escape="query = ''"
                placeholder="{{ __('project_page.papers.search') }}" aria-label="{{ __('project_page.papers.search') }}" autocomplete="off">
            <x-hc.icon name="search" :size="18"/>
        </div>
    </header>

    <div class="hc-pj-recent" x-show="recent.length > 0 && query.trim() === ''" x-cloak>
        <h3>{{ __('project_page.papers.recent') }}</h3>
        <div class="hc-pj-recent-list">
            <template x-for="item in recent" :key="item.key">
                <a class="hc-chip" :href="item.href" :target="item.target" rel="noopener" x-text="item.label" @click="remember(item.key)"></a>
            </template>
        </div>
    </div>

    <div class="hc-pj-paper-groups">
        @foreach($page['papers'] as $group)
        <div class="hc-pj-paper-group" data-paper-group x-show="groupShown($el)">
            <h3 id="pj-papers-{{ $group['key'] }}">{{ $group['label'] }}</h3>
            <ul aria-labelledby="pj-papers-{{ $group['key'] }}">
                @foreach($group['items'] as $item)
                <li data-search="{{ $item['label'] }} {{ $group['label'] }}@if($item['entry']) {{ __('project_page.papers.entry') }}@endif" x-show="matches($el)">
                    <a @class(['hc-pj-paper', 'is-entry' => $item['entry']]) href="{{ $item['url'] }}" data-paper="{{ $item['key'] }}" data-label="{{ $item['label'] }}"
                        @unless($item['entry']) target="_blank" rel="noopener" @endunless @click="remember('{{ $item['key'] }}')" @auxclick="remember('{{ $item['key'] }}')">
                        <x-hc.icon :name="$item['entry'] ? 'edit' : 'printer'" :size="18"/>
                        <span>{{ $item['label'] }}</span>
                        @if($item['entry'])<small>{{ __('project_page.papers.entry') }}</small>@else<span class="sr-only">({{ __('project_page.papers.new_tab') }})</span>@endif
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
        @endforeach
    </div>
    <p class="hc-pj-no-paper muted" x-show="nothing()" x-cloak role="status">{{ __('project_page.papers.none') }}</p>
</section>

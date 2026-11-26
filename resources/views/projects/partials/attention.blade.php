@if($page['attention'] !== [])
<section class="hc-pj-attention" aria-labelledby="pj-attention-title"
    x-data="{ files: {{ $page['files'] ? $page['files']['visible'] + $page['files']['hidden'] : 0 }}, others: {{ collect($page['attention'])->where('key', '!=', 'attachments')->count() }} }"
    @attachments-changed.window="files = $event.detail.total" x-show="others > 0 || files === 0">
    <h2 id="pj-attention-title"><x-hc.icon name="warning" :size="18"/>{{ __('project_page.attention.title') }}</h2>
    <ul>
        @foreach($page['attention'] as $item)
            <li class="is-{{ $item['tone'] }}" data-attention="{{ $item['key'] }}" @if($item['key'] === 'attachments') x-show="files === 0" @endif>
                <span class="hc-pj-dot" aria-hidden="true"></span>
                <span>{{ $item['text'] }}@if($item['url']) <a class="hc-link" href="{{ $item['url'] }}">{{ $item['action'] }}</a>@endif</span>
            </li>
        @endforeach
    </ul>
</section>
@endif

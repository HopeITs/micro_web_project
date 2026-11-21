@if($rows->lastPage() > 1)
<div class="hc-pagination">
    <span>{{ __('hc.page', ['page' => number_format($rows->currentPage()), 'pages' => number_format($rows->lastPage())]) }}</span>
    <nav aria-label="pagination">
        @if($rows->onFirstPage())<x-hc.button variant="secondary" size="sm" disabled>{{ __('hc.previous') }}</x-hc.button>@else<x-hc.button variant="secondary" size="sm" :href="$rows->previousPageUrl()">{{ __('hc.previous') }}</x-hc.button>@endif
        @if($rows->hasMorePages())<x-hc.button variant="secondary" size="sm" :href="$rows->nextPageUrl()">{{ __('hc.next') }}</x-hc.button>@else<x-hc.button variant="secondary" size="sm" disabled>{{ __('hc.next') }}</x-hc.button>@endif
    </nav>
</div>
@endif

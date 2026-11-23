@php
    $locale = app()->getLocale();
    $en = $locale === 'en';
    $isPdf = $original && $original['format'] === 'pdf';
    $originalUrl = $original ? route('papers.original', ['locale' => $locale, 'form' => $form, 'city' => $original['city'], 'place' => $original['place'], 'variant' => $original['variant'], 'format' => $original['format']]) : null;
@endphp
<x-layouts.hc :title="__('papers.review_title').' · '.$title">
    <style>
        .review { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .review > section { border: 1px solid var(--line); border-radius: var(--radius-sm); background: var(--surface); overflow: hidden; }
        .review h2 { margin: 0; padding: 8px 12px; font-size: 1rem; border-bottom: 1px solid var(--line); }
        .review iframe, .review img { display: block; width: 100%; height: 78vh; border: 0; background: #fff; object-fit: contain; }
        @media (max-width: 900px) { .review { grid-template-columns: 1fr; } }
    </style>
    <div class="hc-page-head">
        <div><p class="hc-eyebrow">{{ __('hc.group_tools') }}</p><h1>{{ __('papers.review_title') }}: {{ $title }}</h1><p>{{ __('papers.review_intro') }}</p></div>
        <x-hc.button variant="secondary" :href="route('papers.index', ['locale' => $locale])">{{ __('papers.title') }}</x-hc.button>
    </div>
    <x-hc.card :flush="true">
        <form class="hc-toolbar" method="GET" action="{{ route('papers.review', ['locale' => $locale, 'form' => $form]) }}">
            <div class="hc-field">
                <label class="hc-label" for="city">{{ __('papers.city') }}</label>
                <select id="city" name="city" class="hc-select" onchange="this.form.submit()">
                    @foreach($cities as $c)<option value="{{ $c->code }}" @selected($city === $c->code)>{{ $en ? $c->name_en : $c->name_ar }}</option>@endforeach</select>
            </div>
            <div class="hc-field grow">
                <label class="hc-label" for="project">{{ __('papers.review_project') }}</label>
                <select id="project" name="project" class="hc-select" onchange="this.form.submit()">
                    @foreach($projects as $p)<option value="{{ $p->id }}" @selected($project === (int) $p->id)>{{ $p->project_number }} · {{ \Illuminate\Support\Str::limit((string) $p->name, 40) }}</option>@endforeach</select>
            </div>
        </form>
    </x-hc.card>
    @if(! $original)
        <x-hc.empty :title="__('papers.none')">{{ __('papers.not_installed') }}</x-hc.empty>
    @else
    <div class="review" style="margin-top:12px">
        <section><h2>{{ __('papers.review_original') }}</h2>
            @if($isPdf)<iframe src="{{ $originalUrl }}" title="{{ __('papers.review_original') }}"></iframe>@else<img src="{{ $originalUrl }}" alt="{{ __('papers.review_original') }}">@endif
        </section>
        <section><h2>{{ __('papers.review_redraw') }}</h2>
            @if($project)<iframe src="{{ route('applications.print', ['locale' => $locale, 'project' => $project]) }}" title="{{ __('papers.review_redraw') }}"></iframe>@else<p style="padding:16px">{{ __('papers.review_no_project') }}</p>@endif
        </section>
    </div>
    @endif
</x-layouts.hc>

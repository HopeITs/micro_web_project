<x-layouts.hc :title="__('kobo.visit.title').' '.$number">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('kobo.index', ['locale' => app()->getLocale()]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('kobo.visit.back') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow"><span class="ltr">{{ $number }}</span> · {{ $project->name }}</p><h1>{{ __('kobo.visit.title') }}</h1></div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:12px">{{ session('status') }}</x-hc.alert>@endif
    <livewire:kobo-visit-form :project-id="$project->id" :submission-id="$submission" :start="$formUid" />
</x-layouts.hc>

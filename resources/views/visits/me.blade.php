<x-layouts.hc :title="__('visits.me.title').' '.\App\Services\ProjectNumberAllocator::display($code, (int) $project->project_number)">
    <p style="margin-block-end:12px"><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('visits.hub', ['locale' => app()->getLocale(), 'project' => $project->id]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('visits.back_visits') }}</a></p>
    <div class="hc-page-head"><div><p class="hc-eyebrow"><x-hc.project-number :code="$code" :number="$project->project_number"/> · {{ $project->name }}</p><h1>{{ __('visits.me.title') }}</h1></div></div>
    @if(session('status'))<x-hc.alert type="success" style="margin-block-end:12px">{{ session('status') }}</x-hc.alert>@endif
    @if(session('partner'))
        @php($partner = session('partner'))
        <x-hc.alert type="info" style="margin-block-end:12px">{{ __('visits.me.partner_offer', ['n' => $partner['number'], 'name' => $partner['name']]) }}
            <a class="hc-btn hc-btn-secondary hc-btn-sm" style="margin-inline-start:8px" href="{{ route('visits.me.create', ['locale' => app()->getLocale(), 'project' => $project->id, 'person' => $partner['id'], 'number' => $partner['number']]) }}">{{ __('visits.me.partner_go') }}</a></x-hc.alert>
    @endif
    <livewire:me-visit-form :project-id="$project->id" :person-id="$person" :visit-id="$visit" :suggested="$number" />
</x-layouts.hc>

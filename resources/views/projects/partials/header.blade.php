@php($applicant = $page['applicant'])
<header class="hc-card hc-pj-head">
    <div class="hc-pj-identity">
        <p class="hc-eyebrow">{{ app()->getLocale() === 'en' ? $project->city->name_en : $project->city->name_ar }} · {{ __('hc.stage_'.$project->application_stage) }}</p>
        <div class="hc-pj-titleline">
            <h1><x-hc.project-number :code="$project->city->code" :number="$project->project_number"/> <span class="hc-pj-name" dir="auto">{{ $project->name }}</span></h1>
            <x-project.copy :value="$number" :label="__('hc.number')"/>
            @if($page['status'])<x-hc.badge :tone="$page['status']['tone']" :icon="$page['status']['icon']" class="hc-pj-status" data-project-status title="{{ __('hc.state') }}">{{ $page['status']['label'] }}</x-hc.badge>@endif
        </div>

        @if($applicant)
        <ul class="hc-pj-contact" aria-label="{{ __('hc.applicant') }}">
            <li>
                <x-hc.icon name="user" :size="17"/><span class="sr-only">{{ __('hc.applicant') }}:</span>
                @if($applicant['url'])<a class="hc-link" href="{{ $applicant['url'] }}" title="{{ __('project_page.open_person') }}">{{ $applicant['name'] }}</a>
                @else<strong>{{ $applicant['name'] }}</strong>@endif
            </li>
            @if($applicant['mobile'] !== '')
            <li>
                <x-hc.icon name="phone" :size="17"/><span class="sr-only">{{ __('hc.mobile') }}:</span>
                @if($applicant['tel'])<a class="hc-link num" dir="ltr" href="tel:{{ $applicant['tel'] }}" title="{{ __('project_page.call') }}">{{ $applicant['mobile'] }}</a>
                @else<span class="num" dir="auto">{{ $applicant['mobile'] }}</span>@endif
                <x-project.copy :value="$applicant['mobile']" :label="__('hc.mobile')"/>
            </li>
            @endif
            @if($applicant['national'] !== '')
            <li>
                <x-hc.icon name="id" :size="17"/><span class="muted">{{ __('hc.national_number') }}</span>
                <span class="num" dir="ltr">{{ $applicant['national'] }}</span>
                <x-project.copy :value="$applicant['national']" :label="__('hc.national_number')"/>
            </li>
            @endif
        </ul>
        @else
        <p class="muted">{{ __('project_page.no_applicant') }}</p>
        @endif
    </div>

    @if($page['write'] || $page['papers'] !== [])
    <div class="hc-pj-actions">
        @if($page['write'])
            <x-hc.button icon="edit" :href="route('applications.edit', ['locale' => app()->getLocale(), 'project' => $project->id])">{{ __('applications.edit') }}</x-hc.button>
        @endif
        @if($page['papers'] !== [])
            <x-hc.button icon="printer" variant="secondary" href="#papers">{{ __('project_page.papers.button') }}</x-hc.button>
        @endif
    </div>
    @endif
</header>

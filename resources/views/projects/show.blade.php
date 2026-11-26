{{--
    The project page. Order of reading: who and what (header, the one place of the status and the applicant) · where the application
    stands and the next step · what needs attention · the project's modules · tabs for the content itself. Every fact appears once;
    the data and every permission come from App\Support\ProjectWorkspace.
--}}
@php($number = \App\Services\ProjectNumberAllocator::display($project->city->code, (int) $project->project_number))
<x-layouts.hc :title="$number">
    <div class="hc-pj" x-data="projectTabs(@js($page['tabs']))">
        <p><a class="hc-btn hc-btn-ghost hc-btn-sm" href="{{ route('projects.index', ['locale' => app()->getLocale()]) }}"><x-hc.icon name="arrow" :size="16" class="hc-back-icon"/>{{ __('hc.back') }}</a></p>

        @include('projects.partials.header')

        @if(session('status'))<x-hc.alert type="success">{{ session('status') }}</x-hc.alert>@endif

        @if($page['status'] || $page['tracks'])
        <div class="hc-pj-journey">
            @if($page['status'])<x-hc.stage-bar :current="$page['status']['path']['current']" :mode="$page['status']['path']['mode']" :tone="$page['status']['path']['tone']"/>@endif
            @if($page['tracks'])<livewire:project-next-step :project-id="$project->id" />@endif
        </div>
        @endif

        @include('projects.partials.attention')
        @include('projects.partials.modules')

        <div class="hc-pj-body">
            <div class="hc-pj-tabs" role="tablist" aria-label="{{ __('project_page.tabs.label') }}" @keydown="key($event)">
                @foreach($page['tabs'] as $tab)
                    <button type="button" role="tab" class="hc-pj-tab" id="pj-tab-{{ $tab }}" data-tab="{{ $tab }}" aria-controls="pj-panel-{{ $tab }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? 0 : -1 }}"
                        :aria-selected="tab === '{{ $tab }}'" :tabindex="tab === '{{ $tab }}' ? 0 : -1" @click="open('{{ $tab }}')">
                        {{ __('project_page.tabs.'.$tab) }}
                        @if($tab === 'attachments')
                            <span class="hc-pj-count num" x-data="{ total: {{ $page['files']['visible'] }} }" @attachments-changed.window="total = $event.detail.visible" x-text="total">{{ $page['files']['visible'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <div class="hc-pj-panel" role="tabpanel" id="pj-panel-overview" data-tab-panel="overview" aria-labelledby="pj-tab-overview" x-show="tab === 'overview'">
                @include('projects.partials.overview')
            </div>
            @if($page['tracks'])
            <div class="hc-pj-panel" role="tabpanel" id="pj-panel-progress" data-tab-panel="progress" aria-labelledby="pj-tab-progress" x-show="tab === 'progress'" x-cloak>
                <div class="hc-pj-columns">
                    <livewire:project-lifecycle :project-id="$project->id" />
                    <livewire:project-tasks :project-id="$project->id" />
                </div>
            </div>
            @endif
            {{-- The panel component decides itself what the account may see; it renders nothing without attachment grants. --}}
            <div class="hc-pj-panel" @if($page['files']) role="tabpanel" id="pj-panel-attachments" data-tab-panel="attachments" aria-labelledby="pj-tab-attachments" x-show="tab === 'attachments'" x-cloak @else hidden @endif>
                <livewire:attachments-panel owner="project" :owner-id="$project->id" :counted="false" />
            </div>
            @if($page['papers'] !== [])
            <div class="hc-pj-panel" role="tabpanel" id="pj-panel-papers" data-tab-panel="papers" aria-labelledby="pj-tab-papers" x-show="tab === 'papers'" x-cloak>
                @include('projects.partials.papers')
            </div>
            @endif
        </div>
    </div>
</x-layouts.hc>

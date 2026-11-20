{{--
    Stage bar (F2, borrowed from HC Tracking's project page): the application path with the current
    stage marked, earlier stages done, and a stopped path (rejected / withdrew / cancelled) shown with
    a cross and a word, not colour alone. Reads in the page direction (RTL in Arabic).
    stages: list of ['key', 'label']; current: a stage key; stopped: bool; note: the state's own name.
--}}
@props(['stages' => \App\Support\ProjectStatus::stages(), 'current' => null, 'stopped' => false, 'note' => null])
@php($index = collect($stages)->search(fn ($s) => $s['key'] === $current))
<ol {{ $attributes->class(['hc-stages', 'is-stopped' => $stopped]) }} aria-label="{{ __('hc.path_title') }}">
    @foreach($stages as $i => $stage)
        @php($state = $index === false ? 'todo' : ($i < $index ? 'done' : ($i === $index ? ($stopped ? 'stopped' : 'now') : 'todo')))
        <li class="hc-stage is-{{ $state }}" @if($state === 'now' || $state === 'stopped') aria-current="step" @endif>
            <span class="hc-stage-dot" aria-hidden="true">@if($state === 'done')<x-hc.icon name="check" :size="12"/>@elseif($state === 'stopped')<x-hc.icon name="x" :size="12"/>@endif</span>
            <span class="hc-stage-label">{{ $stage['label'] }}</span>
            @if($state === 'now')<span class="hc-stage-tag">{{ $note ?? __('hc.path_now') }}</span>@elseif($state === 'stopped')<span class="hc-stage-tag">{{ $note ?? __('hc.path_stopped') }}</span>@endif
            <span class="sr-only">{{ __('hc.path_state_'.$state) }}</span>
        </li>
    @endforeach
</ol>

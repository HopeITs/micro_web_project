{{--
    Stage bar (F2, borrowed from HC Tracking's project page; path approved by the owner 20 Nov 2026):
    the application path with earlier stages done and the current one marked by its mode —
    now (red ring), paused (amber ring, delayed), stopped (red ✕: rejected, cancelled),
    withdrawn (grey ✕: the beneficiary's choice) or complete (every stage done: finished, closed).
    The state's own name sits under the step in its tone; never colour alone. Reads in page direction.
    Usually fed from ProjectStatus::path(): current, mode and tone, with note = the state's name.
--}}
@props(['stages' => \App\Support\ProjectStatus::stages(), 'current' => null, 'mode' => 'now', 'tone' => null, 'note' => null])
@php
    $index = collect($stages)->search(fn ($s) => $s['key'] === $current);
    $last = count($stages) - 1;
@endphp
<ol {{ $attributes->class(['hc-stages', 'is-'.$mode]) }} aria-label="{{ __('hc.path_title') }}">
    @foreach($stages as $i => $stage)
        @php
            $state = match (true) {
                $index === false => 'todo',
                $i < $index => 'done',
                $i > $index => 'todo',
                $mode === 'complete' => 'done',
                default => $mode,
            };
            $here = $index !== false && $i === $index;
        @endphp
        <li @class(['hc-stage', 'is-'.$state, 'is-here' => $here]) @if($here) aria-current="step" @endif>
            <span class="hc-stage-dot" aria-hidden="true">@if($state === 'done')<x-hc.icon name="check" :size="12"/>@elseif(in_array($state, ['stopped', 'withdrawn'], true))<x-hc.icon name="x" :size="12"/>@elseif($state === 'paused')<x-hc.icon name="pause" :size="11"/>@endif</span>
            <span class="hc-stage-label">{{ $stage['label'] }}</span>
            @if($here && $note)<span @class(['hc-stage-tag', 'hc-tone-'.($tone ?? 'neutral')])>{{ $note }}</span>@endif
            <span class="sr-only">{{ __('hc.path_state_'.($here && $mode === 'complete' ? 'complete' : $state)) }}</span>
        </li>
    @endforeach
</ol>

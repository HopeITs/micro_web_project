@php($en = app()->getLocale() === 'en')
<div id="tasks">
@if($card !== null)
<x-hc.card :title="__('lifecycle.tasks.title')">
    @if($card['tasks']->isEmpty())
        <p class="muted" style="margin:0">{{ __('lifecycle.tasks.none') }}</p>
    @else
        @php($done = $card['tasks']->where('done', true)->count())
        <p class="muted" style="margin:0 0 10px">{{ __('lifecycle.tasks.progress', ['done' => $done, 'all' => $card['tasks']->count()]) }}</p>
        <div class="hc-progress" style="margin-block-end:14px"><span style="width: {{ round($done / $card['tasks']->count() * 100) }}%"></span></div>
        @foreach(['a' => 'study', 'f' => 'followup'] as $type => $group)
            @php($rows = $card['tasks']->where('type', $type))
            @if($rows->isNotEmpty())
            <h3 style="font-size:.9rem;margin:12px 0 6px">{{ __('lifecycle.tasks.'.$group) }}</h3>
            <ul class="hc-tasks">
                @foreach($rows as $task)
                    @php($can = in_array((int) $task->task_definition_id, $card['tickable'], true))
                    <li @class(['is-done' => $task->done])>
                        <label>
                            <input type="checkbox" @checked($task->done) @disabled(! $can)
                                wire:click="toggle({{ $task->id }}, {{ $task->done ? 'false' : 'true' }})" wire:loading.attr="disabled"
                                aria-describedby="task-{{ $task->id }}-meta">
                            <span>{{ $en && $task->name_en ? $task->name_en : $task->name_ar }}</span>
                        </label>
                        <span class="muted num" id="task-{{ $task->id }}-meta">@if($task->done){{ \Illuminate\Support\Str::limit((string) $task->task_date, 10, '') }}@elseif(! $can){{ __('lifecycle.tasks.not_yours') }}@endif</span>
                    </li>
                @endforeach
            </ul>
            @endif
        @endforeach
    @endif
</x-hc.card>
@endif
</div>

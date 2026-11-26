{{-- Copy a value to the clipboard (also on a plain-http office address); the button says when it worked. --}}
@props(['value', 'label'])
<span {{ $attributes->class('hc-pj-copy') }} x-data="pjCopy">
    <button type="button" data-copy="{{ $value }}" @click="copy($el.dataset.copy)" aria-label="{{ __('project_page.copy', ['what' => $label]) }}" title="{{ __('project_page.copy', ['what' => $label]) }}">
        <x-hc.icon name="copy" :size="15" x-show="!done"/><x-hc.icon name="check" :size="15" x-show="done" x-cloak/>
    </button>
    <span class="sr-only" role="status" x-text="done ? $el.dataset.done : ''" data-done="{{ __('project_page.copied') }}"></span>
</span>

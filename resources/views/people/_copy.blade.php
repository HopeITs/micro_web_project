{{-- Copy a value to the clipboard (resources/js/modules/people.js). $text; the value never leaves the page. --}}
<button type="button" class="hc-icon-btn fam-copy" x-data="hcCopy" data-copy="{{ $text }}" @click="copy()" title="{{ __('families.copy') }}" aria-label="{{ __('families.copy') }}">
    <x-hc.icon name="copy" :size="16" x-show="!done"/><x-hc.icon name="check" :size="16" x-show="done" x-cloak/>
    <span class="sr-only" role="status" x-text="done ? @js(__('families.copied')) : ''"></span>
</button>

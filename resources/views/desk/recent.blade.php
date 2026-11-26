{{-- Recently opened projects: ids and project numbers only, kept in this browser (localStorage `mp.recent.projects`, a list of {id, no}). Never names. --}}
<section aria-labelledby="desk-recent" wire:ignore x-data="{
        items: [],
        init() {
            try {
                const list = JSON.parse(localStorage.getItem('mp.recent.projects') || '[]');
                this.items = (Array.isArray(list) ? list : []).filter((r) => r && Number.isInteger(r.id) && r.id > 0 && /^[A-Za-z]{2,6}-[0-9]{1,9}$/.test(String(r.no))).slice(0, 6);
            } catch (_) { this.items = []; }
        },
        url(id) { return @js(route('projects.show', ['locale' => app()->getLocale(), 'project' => 987654321])).replace('987654321', String(id)); },
    }">
    <h2 id="desk-recent" style="font-size:1.05rem;margin:0 0 10px">{{ __('desk.recent') }}</h2>
    <div class="hc-chips" style="flex-wrap:wrap" x-show="items.length">
        <template x-for="item in items" :key="item.id"><a class="hc-chip num ltr" :href="url(item.id)" x-text="item.no"></a></template>
    </div>
    <p class="muted" x-show="!items.length">{{ __('desk.recent_none') }}</p>
</section>

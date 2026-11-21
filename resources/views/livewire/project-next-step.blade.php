<div>
@if($step)
    <x-hc.next-step :title="$step['title']" :waiting="$step['waiting']" :href="$step['href']" :action="$step['action']" :since="$step['since']" style="margin-block-end:20px"/>
@endif
</div>

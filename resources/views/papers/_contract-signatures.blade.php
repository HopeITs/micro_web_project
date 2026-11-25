{{-- The foot of a sale contract: the place and date, then the two witnesses and the two parties. --}}
@php($L = fn ($key) => __('paper_deeds.'.$key))
<div style="text-align:center;margin-top:6mm">
    <p style="margin:0"><x-paper.fill :value="$d['place']" :w="26"/></p>
    <p style="margin:2mm 0 0" dir="ltr"><x-paper.fill :value="$d['signed_on']" :w="42"/></p>
</div>
<div class="sigs" style="--c:4;margin-top:6mm">
    <div>{{ $L('witness') }}<br><small>{{ $d['witness_1'] }}</small></div>
    <div>{{ $L('witness') }}<br><small>{{ $d['witness_2'] }}</small></div>
    <div>{{ $L('first_party') }}<br><small>{{ $d['seller'] }}</small></div>
    <div>{{ $L('second_party') }}<br><small>{{ $d['party']['name'] }}</small></div>
</div>

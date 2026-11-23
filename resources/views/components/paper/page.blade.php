{{-- One printed page of a paper: the letterhead on top and the city's address in the footer, repeated if the content runs onto the next sheet. --}}
@props(['city' => 'ALP'])
@php($footer = \App\Services\Papers\PaperCity::footer($city))
<section class="sheet">
    <table class="frame">
        <thead><tr><td><img src="{{ asset('assets/brand/checklist-header.png') }}" alt="Hope Center"></td></tr></thead>
        <tbody><tr><td>{{ $slot }}</td></tr></tbody>
        <tfoot><tr><td><b>Address:</b> {{ $footer['address'] }} &nbsp; <b>Mobile:</b> <bdi dir="ltr">{{ $footer['mobile'] }}</bdi></td></tr></tfoot>
    </table>
</section>

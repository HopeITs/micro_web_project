@props(['code', 'number'])
<span {{ $attributes->merge(['class' => 'hc-pnum']) }}>{{ \App\Services\ProjectNumberAllocator::display($code, (int) $number) }}</span>

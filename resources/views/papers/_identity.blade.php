{{-- The identity run of a legal paper: بن … والدته … تولد … قيد … رقم وطني … منح … (or the identity document for a non-Syrian). $i is FinancePaper's identity array. --}}
@php($L = fn ($key) => __('paper_finance.'.$key))
{{ $L('son_of') }} <x-paper.fill :value="$i['father']" :w="22"/>
@if(($mother ?? true)){{ $L('and_mother') }} <x-paper.fill :value="$i['mother']" :w="22"/>@endif
{{ $L('born') }} <x-paper.fill :value="str_replace('-', '/', $i['birth'])" :w="24"/>
{{ $L('registry') }} <x-paper.fill :value="$i['registry']" :w="24"/>
@if($i['other'] !== '')
    {{ $i['doc_type'] !== '' ? $i['doc_type'] : $L('document') }} <x-paper.fill :value="$i['doc_number']" :w="30"/>
@else
    {{ $L('national') }} <x-paper.fill :value="$i['national']" :w="30"/> {{ $L('issued') }} <x-paper.fill :value="$i['issue']" :w="24"/>
@endif

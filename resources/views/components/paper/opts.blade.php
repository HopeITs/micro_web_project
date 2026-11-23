{{-- Round tick options: $options is a list of labels (or [value => label]); $chosen is the ticked value (null = none ticked). --}}
@props(['options', 'chosen' => null])
@foreach($options as $value => $label)<span class="opt">{{ $label }}<i class="rb {{ (string) $chosen !== '' && (string) $chosen === (string) $value ? 'on' : '' }}"></i></span> @endforeach

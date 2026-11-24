{{-- M4.20 print engine: the standalone A4 document every official paper prints in (letterhead and footer per page come from paper.page). --}}
@props(['title', 'back' => null, 'print' => null])
@php($ar = app()->isLocale('ar'))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style>
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SomarSans-Regular.otf') format('opentype'); font-weight: 400; }
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SOMARSANS-MEDIUM.OTF') format('opentype'); font-weight: 500; }
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SOMARSANS-BOLD.OTF') format('opentype'); font-weight: 700; }
        :root { color-scheme: light; --red: #be1f24; --bar: #b9382b; --grey: #e7e7e8; --line: #8a8a8a; --ink: #231f20; --hint: #6d6e71; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #cfd0d2; font-family: "Somar Sans", "Segoe UI", Tahoma, Arial, sans-serif; font-size: 9pt; line-height: 1.4; color: var(--ink); }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: .6rem; align-items: center; padding: .6rem 1rem; background: #231f20; color: #fff; }
        .toolbar a, .toolbar button { font: inherit; padding: .4rem .9rem; border: 1px solid #fff; background: transparent; color: #fff; border-radius: 4px; text-decoration: none; cursor: pointer; }
        .toolbar .title { margin-inline-start: auto; opacity: .85; }
        .sheet { width: 210mm; margin: 12px auto; background: #fff; padding: 0 12mm; box-shadow: 0 1px 6px rgba(0,0,0,.35); break-after: page; }
        .sheet:last-of-type { break-after: auto; }
        .frame { width: 100%; height: 275mm; border-collapse: collapse; }
        .frame > thead img { display: block; width: 100%; }
        .frame > tbody > tr > td { vertical-align: top; padding: 0; }
        .frame > tfoot td { text-align: center; font-size: 8.5pt; padding-top: 2mm; }
        .frame > tfoot b { color: var(--red); font-weight: 700; }
        .frame > tfoot .pg { display: block; font-size: 7pt; color: var(--hint); }
        .p-title { text-align: center; font-size: 17pt; font-weight: 700; margin: 1mm 0 4mm; }
        .bar { background: var(--bar); color: #fff; text-align: center; font-weight: 700; font-size: 12pt; padding: .6mm 2mm; margin: 2mm 0 3mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; break-after: avoid; }
        .sub { position: relative; text-align: center; color: var(--bar); font-weight: 700; font-size: 11pt; margin: 3.5mm 0 1mm; break-after: avoid; }
        .sub .opts { position: absolute; inset-inline-end: 0; top: 50%; transform: translateY(-50%); font-size: 9pt; font-weight: 500; display: flex; gap: 4mm; color: var(--bar); }
        .row { display: grid; grid-template-columns: repeat(var(--c, 2), minmax(0, 1fr)); gap: 0 6mm; margin: 1.1mm 0; break-inside: avoid; }
        .f { display: flex; align-items: flex-end; gap: 2mm; min-width: 0; }
        .f .l { white-space: nowrap; }
        .f .opts { flex: 1; min-width: 0; display: flex; flex-wrap: wrap; gap: 1mm 3mm; }
        .f .v { flex: 1; min-width: 0; border-bottom: 1px dotted #444; min-height: 1.35em; padding-inline: 1mm; overflow-wrap: anywhere; font-weight: 500; }
        .rb { display: inline-block; width: .85em; height: .85em; border: 1px solid #666; border-radius: 50%; vertical-align: -.1em; margin-inline: 1mm; }
        .rb.on { background: #231f20; box-shadow: inset 0 0 0 2px #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .opt { white-space: nowrap; }
        table.g { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 1.5mm 0; }
        table.g th { background: var(--grey); border: 1px solid var(--line); padding: .8mm .6mm; font-size: 8pt; text-align: center; font-weight: 700; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.g td { border: 1px solid var(--line); padding: .5mm .8mm; height: 6mm; font-size: 8pt; text-align: center; vertical-align: middle; overflow-wrap: anywhere; font-weight: 500; }
        table.g tr { break-inside: avoid; }
        .note { font-size: 6.2pt; color: var(--hint); margin: .3mm 0; line-height: 1.3; }
        .lined { line-height: 6mm; min-height: calc(var(--n, 2) * 6mm); background-image: repeating-linear-gradient(to bottom, transparent 0, transparent calc(6mm - 1px), #999 calc(6mm - 1px), #999 6mm); -webkit-print-color-adjust: exact; print-color-adjust: exact; white-space: pre-wrap; overflow-wrap: anywhere; font-weight: 500; }
        .q { font-weight: 700; margin: 3mm 0 .5mm; break-after: avoid; }
        .q small { font-weight: 400; font-size: 8pt; color: var(--hint); }
        .goals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.8mm 4mm; margin: 3mm 0; font-size: 8.5pt; }
        .ask { display: grid; grid-template-columns: 1fr auto; gap: 2mm 4mm; align-items: center; margin: .9mm 0; font-size: 8.8pt; }
        .sigs { display: grid; grid-template-columns: repeat(var(--c, 3), 1fr); gap: 8mm; margin-top: 8mm; text-align: center; font-size: 11pt; }
        .sigs div { border-bottom: 1px dotted #444; padding-bottom: 9mm; }
        .fill { display: inline-block; border-bottom: 1px dotted #444; font-weight: 500; padding-inline: 1mm; text-align: center; min-height: 1.2em; vertical-align: bottom; }
        .legal { font-size: 11pt; line-height: 2.1; text-align: justify; }
        .legal p { margin: 0 0 1mm; }
        .photo { width: 32mm; height: 36mm; border: 1px solid var(--line); }
        .intro { display: grid; grid-template-columns: 1fr 36mm; gap: 6mm; align-items: start; }
        @page { size: A4; margin: 8mm 12mm 14mm; @bottom-center { content: "PAGE:" counter(page) "/" counter(pages); font-size: 7pt; color: #6d6e71; } }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
            .frame > tfoot .pg { display: none; }
        }
    </style>
</head>
<body>
@if($back || $print !== false)
    <div class="toolbar">
        @if($print !== false)<button type="button" onclick="window.print()">{{ __('papers.print') }}</button>@endif
        @if($back)<a href="{{ $back }}">{{ __('papers.back') }}</a>@endif
        <span class="title">{{ $title }}</span>
    </div>
@endif
{{ $slot }}
</body>
</html>

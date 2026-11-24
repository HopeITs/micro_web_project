@php($ar = app()->isLocale('ar'))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Check List · {{ \App\Services\ProjectNumberAllocator::display($city->code, (int) $project->project_number) }}</title>
    <style>
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SomarSans-Regular.otf') format('opentype'); font-weight: 400; }
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SOMARSANS-MEDIUM.OTF') format('opentype'); font-weight: 500; }
        @font-face { font-family: 'Somar Sans'; src: url('/assets/fonts/SOMARSANS-BOLD.OTF') format('opentype'); font-weight: 700; }
        :root { color-scheme: light; --red: #be1f24; --grey: #f1f2f2; --line: #888; }
        * { box-sizing: border-box; }
        body { font-family: "Somar Sans", "Segoe UI", Tahoma, Arial, sans-serif; font-size: 10.5pt; color: #231f20; background: #fff; margin: 0; padding: 1.5rem; line-height: 1.55; }
        main { max-width: 190mm; margin: 0 auto; }
        .letterhead { width: 100%; display: block; }
        h1 { font-size: 22pt; font-weight: 700; text-align: center; margin: 1.2rem 0 1.4rem; }
        section { margin-bottom: 1.4rem; }
        .sec { background: var(--red); color: #fff; text-align: center; font-weight: 700; font-size: 12pt; padding: .2rem .5rem; -webkit-print-color-adjust: exact; print-color-adjust: exact; break-after: avoid; }
        .sub { background: var(--grey); text-align: center; font-weight: 500; padding: .15rem .5rem; margin-top: .4rem; -webkit-print-color-adjust: exact; print-color-adjust: exact; break-after: avoid; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th, table.grid td { border: 1px solid var(--line); border-top: 0; padding: .3rem .5rem; text-align: start; vertical-align: top; overflow-wrap: anywhere; white-space: pre-wrap; }
        table.grid th { font-weight: 500; }
        b { font-weight: 500; }
        .box { border: 1px solid var(--line); border-top: 0; padding: .35rem .5rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        .bar { display: flex; gap: .75rem; margin-bottom: 1rem; }
        .bar a, .bar button { font: inherit; padding: .5rem 1rem; border: 1px solid #111; background: #fff; color: #111; border-radius: 4px; text-decoration: none; cursor: pointer; }
        .foot { color: #555; font-size: 9pt; }
        tr { break-inside: avoid; }
        @page { size: A4; margin: 10mm 12mm 14mm; }
        @media print { body { padding: 0; } .bar { display: none; } }
    </style>
</head>
<body>
<main>
    <div class="bar"><button type="button" onclick="window.print()">{{ __('checklists.print') }}</button><a href="{{ route('checklists.show', ['locale' => app()->getLocale(), 'project' => $project->id]) }}">{{ __('hc.back') }}</a></div>
@include('checklists._paper', get_defined_vars())
</main>
</body>
</html>

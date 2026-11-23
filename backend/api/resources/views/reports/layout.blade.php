<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { margin: 70px 36px 50px 36px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #0f172a; }
    header { position: fixed; top: -52px; left: 0; right: 0; border-bottom: 2px solid #0f4c81; padding-bottom: 6px; }
    header .inst { font-size: 10px; color: #475569; }
    header .title { font-size: 15px; font-weight: bold; color: #0f4c81; }
    footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 8px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 4px; }
    footer .page:after { content: counter(page); }
    h2 { font-size: 11.5px; color: #0f4c81; margin: 16px 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    th { background: #0f4c81; color: #fff; text-align: left; padding: 4px 5px; font-size: 8.5px; }
    td { padding: 3.5px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    tr:nth-child(even) td { background: #f8fafc; }
    .meta td { border: none; padding: 1px 5px 1px 0; background: none !important; }
    .meta td:first-child { color: #64748b; width: 120px; }
    .kpis { width: 100%; margin: 4px 0 6px; }
    .kpis td { border: 1px solid #e2e8f0; text-align: center; padding: 6px; background: #fff !important; }
    .kpis .v { font-size: 16px; font-weight: bold; color: #0f4c81; }
    .kpis .l { font-size: 8px; color: #64748b; }
    .red { color: #b91c1c; font-weight: bold; }
    .green { color: #047857; }
    .muted { color: #64748b; }
    .pre { white-space: pre-line; }
    .note { font-size: 8.5px; color: #92400e; background: #fffbeb; padding: 4px 6px; }
</style>
</head>
<body>
<header>
    <div class="inst">{{ $institution }}</div>
    <div class="title">{{ $title }}</div>
</header>
<footer>
    {{ __('reports.generated_by', ['name' => $generatedBy, 'date' => now()->timezone($timezone)->format('d/m/Y H:i')]) }}
    — COUSP Task Manager — {{ __('reports.page') }} <span class="page"></span>
</footer>
<main>
@yield('content')
</main>
</body>
</html>

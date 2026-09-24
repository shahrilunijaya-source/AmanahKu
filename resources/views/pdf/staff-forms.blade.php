<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 24px 28px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
</style>
</head>
<body>
    @foreach ($forms as $data)
        <div class="page">@include('partials.payroll.form.lhdn-form', ['data' => $data, 'title' => $title, 'year' => $year])</div>
    @endforeach
</body>
</html>

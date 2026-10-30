{{-- SIS.EXP-4: the ONE generic certificate template (code-owned; every value is an escaped snapshot field). --}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $type->label() }} {{ $displayNumber }}</title>
<style>
    @page { margin: 22mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; color: #111; }
    .banner { border: 2px solid #b00; color: #b00; text-align: center; font-weight: bold; padding: 6px; margin-bottom: 14px; font-size: 10pt; }
    .school { text-align: center; }
    .school h1 { font-size: 17pt; margin: 0; }
    .school p { margin: 2px 0; font-size: 9.5pt; color: #333; }
    h2 { text-align: center; font-size: 15pt; letter-spacing: 1px; margin: 22px 0 4px; text-transform: uppercase; }
    .meta { text-align: center; font-size: 10pt; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; width: 42%; font-weight: normal; color: #333; padding: 6px 8px; border-bottom: 1px solid #ccc; vertical-align: top; }
    td { padding: 6px 8px; border-bottom: 1px solid #ccc; font-weight: bold; vertical-align: top; }
    .statement { margin: 18px 0; line-height: 1.5; }
    .sign { margin-top: 48px; width: 100%; }
    .sign td { border: 0; font-weight: normal; padding: 0; }
    .line { border-top: 1px solid #111; width: 60mm; padding-top: 4px; font-size: 9.5pt; }
    .foot { margin-top: 26px; font-size: 8pt; color: #555; border-top: 1px solid #ddd; padding-top: 6px; }
</style>
</head>
<body>
@if ($demo)
    <div class="banner">DEMO / GENERIC CERTIFICATE TEMPLATE &mdash; NOT A STATUTORY DOCUMENT</div>
@endif
<div class="school">
    <h1>{{ $s['school_name'] }}</h1>
    @if ($s['school_legal_name'] !== null && $s['school_legal_name'] !== $s['school_name'])
        <p>{{ $s['school_legal_name'] }}</p>
    @endif
    @if ($s['school_affiliation_reference'] !== null)
        <p>Affiliation: {{ $s['school_affiliation_reference'] }}</p>
    @endif
    @if ($s['school_registration_reference'] !== null)
        <p>Registration: {{ $s['school_registration_reference'] }}</p>
    @endif
</div>

<h2>{{ $type->label() }}</h2>
<p class="meta">Certificate number {{ $displayNumber }} &middot; Issued on {{ \Carbon\CarbonImmutable::parse($issuedOn)->format('j F Y') }}</p>

<table>
    <tr><th>Student name</th><td>{{ $s['student_name'] }}</td></tr>
    <tr><th>Student number</th><td>{{ $s['student_number'] }}</td></tr>
    @if ($s['joined_on'] !== null)
        <tr><th>Date of joining</th><td>{{ \Carbon\CarbonImmutable::parse($s['joined_on'])->format('j F Y') }}</td></tr>
    @endif
    <tr><th>Date of leaving</th><td>{{ \Carbon\CarbonImmutable::parse($s['left_on'])->format('j F Y') }}</td></tr>
    <tr><th>Last class attended</th><td>{{ $s['grade_name'] }} &middot; Section {{ $s['section_name'] }}</td></tr>
    <tr><th>Academic year</th><td>{{ $s['academic_year_name'] }}</td></tr>
    <tr><th>Campus</th><td>{{ $s['campus_name'] }}</td></tr>
    <tr><th>Reason for leaving</th><td>{{ $reason }}</td></tr>
</table>

<p class="statement">
    This is to certify that the Student named above was enrolled at {{ $s['school_name'] }} and left the School on the date
    shown, according to the School's records on the date of issue.
</p>

<table class="sign">
    <tr>
        <td><div class="line">Authorised signatory</div></td>
        <td style="text-align: right;"><div class="line" style="margin-left: auto;">School seal</div></td>
    </tr>
</table>

<div class="foot">
    Generic certificate template issued through Lycenza. It is not a government-, board- or statute-prescribed form,
    and it carries no financial information. Each certificate number is issued once; a corrected certificate carries
    a new number.
</div>
</body>
</html>

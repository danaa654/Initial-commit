<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $curriculum->display_name }} — Prospectus</title>
    <style>
        @page { size: 8.5in 13in; margin: 10mm; } /* long bond paper (8.5x13), portrait */

        * { box-sizing: border-box; }

        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }

        body {
            font-family: Arial, 'Segoe UI', sans-serif;
            color: #000;
            font-size: 9px;
            margin: 0;
        }

        .letterhead {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .letterhead .academy {
            font-size: 14px;
            font-weight: 700;
        }

        .letterhead .sub { font-size: 9px; margin: 1px 0; }
        .letterhead .tagline { font-style: italic; font-size: 9px; }

        .prospectus-title {
            text-align: center;
            margin: 6px 0 12px;
        }

        .prospectus-title .program {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .prospectus-title .major {
            font-size: 10px;
            font-weight: 700;
        }

        .prospectus-title .meta { font-size: 9px; }

        .row { display: flex; gap: 8px; margin-bottom: 8px; }
        .col { flex: 1 1 0; min-width: 0; }

        .sem-title {
            background: #000;
            color: #fff;
            font-weight: 700;
            font-size: 9.5px;
            text-align: center;
            padding: 3px 6px;
            text-transform: uppercase;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.items th, table.items td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
        }

        table.items thead th {
            background: #f2f2f2;
            font-size: 7.8px;
            font-weight: 700;
            text-align: center;
        }

        table.items td {
            font-size: 8px;
        }

        table.items td.center, table.items th.center { text-align: center; }

        col.code { width: 10%; }
        col.title { width: 33%; }
        col.hrs { width: 7%; }
        col.units { width: 8%; }
        col.prereq { width: 20%; }
        col.grades { width: 8%; }

        tr.total-row td {
            font-weight: 700;
            background: #f7f7f7;
            text-align: center;
        }

        tr.total-row td.label { text-align: right; }

        .summary-wrap {
            margin-top: 14px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }

        table.summary {
            border-collapse: collapse;
            font-size: 9px;
            min-width: 260px;
        }

        table.summary caption {
            text-align: left;
            font-weight: 700;
            font-size: 9.5px;
            padding-bottom: 3px;
        }

        table.summary th, table.summary td {
            border: 1px solid #000;
            padding: 2px 6px;
        }

        table.summary th { background: #f2f2f2; text-align: center; }
        table.summary td.label { font-style: italic; }
        table.summary td.val { text-align: center; width: 55px; }
        table.summary tr.grand td { font-weight: 700; font-style: normal; }

        .print-bar { text-align: right; margin-bottom: 10px; }

        .print-bar button {
            background: #D4A62A;
            color: #111;
            border: none;
            padding: 7px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }

        @media print {
            .print-bar { display: none; }
        }
    </style>
</head>
<body>

    <div class="print-bar">
        <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <div class="letterhead">
        <div class="academy">Professional Academy of the Philippines</div>
        <div class="sub">South Poblacion, City of Naga, Cebu</div>
        <div class="sub">Tel. No. (032) 273 6484 &nbsp;&nbsp; Email: papregistrar@gmail.com</div>
        <div class="tagline">"Your Future... Our Promise"</div>
    </div>

    <div class="prospectus-title">
        <div class="program">{{ $curriculum->program->name }}</div>
        @if($curriculum->specialization)
            <div class="major">Major in {{ $curriculum->specialization->name }}</div>
        @endif
        <div class="meta">A.Y. {{ $curriculum->curriculum_range }}</div>
    </div>

    @php
        $yearLabels = [1 => 'FIRST YEAR', 2 => 'SECOND YEAR', 3 => 'THIRD YEAR', 4 => 'FOURTH YEAR', 5 => 'FIFTH YEAR'];

        // Running per-column totals for the bottom Summary of Units box.
        $grandLecTotal = 0;
        $grandLabTotal = 0;
        $grandUnitsTotal = 0;
    @endphp

    @foreach($yearLevels as $yearLevel)
        @php
            $firstSem = $grouped->get("{$yearLevel}-1", collect());
            $secondSem = $grouped->get("{$yearLevel}-2", collect());
            $summer = $grouped->get("{$yearLevel}-3", collect());
            $yearLabel = $yearLabels[$yearLevel] ?? "YEAR {$yearLevel}";
        @endphp

        <div class="row">
            @foreach([['FIRST SEMESTER', $firstSem], ['SECOND SEMESTER', $secondSem]] as [$semLabel, $rows])
                @php
                    $lecTotal = $rows->sum(fn($i) => (float) ($i->subject->lecture_hours ?? 0));
                    $labTotal = $rows->sum(fn($i) => (float) ($i->subject->laboratory_hours ?? 0));
                    $unitsTotal = $rows->sum(fn($i) => (float) ($i->subject->units ?? 0));
                    $grandLecTotal += $lecTotal;
                    $grandLabTotal += $labTotal;
                    $grandUnitsTotal += $unitsTotal;
                @endphp
                <div class="col">
                    <div class="sem-title">{{ $yearLabel }} - {{ $semLabel }}</div>
                    <table class="items">
                        <colgroup>
                            <col class="code"><col class="title">
                            <col class="hrs"><col class="hrs">
                            <col class="units"><col class="prereq"><col class="grades">
                        </colgroup>
                        <thead>
                            <tr>
                                <th rowspan="2">SUBJECT<br>CODE</th>
                                <th rowspan="2">DESCRIPTIVE TITLE</th>
                                <th colspan="2">NO. HOURS</th>
                                <th rowspan="2">UNITS</th>
                                <th rowspan="2">PRE-<br>REQUISITES</th>
                                <th rowspan="2">GRADES</th>
                            </tr>
                            <tr>
                                <th>LEC</th>
                                <th>LAB</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $item)
                                <tr>
                                    <td>{{ $item->display_code }}</td>
                                    <td>
                                        {{ $item->display_title }}
                                        @if($item->isOjt())
                                            <br><span style="font-style:italic;">({{ $item->ojt_hours }} hours)</span>
                                        @endif
                                    </td>
                                    <td class="center">{{ $item->subject->lecture_hours ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->laboratory_hours ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->units ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->prerequisite->subject_code ?? 'None' }}</td>
                                    <td></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="center" style="color:#999;">No items</td>
                                </tr>
                            @endforelse

                            @if($rows->isNotEmpty())
                                <tr class="total-row">
                                    <td colspan="2" class="label">TOTAL</td>
                                    <td class="center">{{ $lecTotal }}</td>
                                    <td class="center">{{ $labTotal }}</td>
                                    <td class="center">{{ $unitsTotal }}</td>
                                    <td colspan="2"></td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>

        @if($summer->isNotEmpty())
            @php
                $sLecTotal = $summer->sum(fn($i) => (float) ($i->subject->lecture_hours ?? 0));
                $sLabTotal = $summer->sum(fn($i) => (float) ($i->subject->laboratory_hours ?? 0));
                $sUnitsTotal = $summer->sum(fn($i) => (float) ($i->subject->units ?? 0));
                $grandLecTotal += $sLecTotal;
                $grandLabTotal += $sLabTotal;
                $grandUnitsTotal += $sUnitsTotal;
            @endphp
            <div class="row">
                <div class="col" style="flex:1 1 100%;">
                    <div class="sem-title">{{ $yearLabel }} - SUMMER</div>
                    <table class="items">
                        <colgroup>
                            <col class="code"><col class="title">
                            <col class="hrs"><col class="hrs">
                            <col class="units"><col class="prereq"><col class="grades">
                        </colgroup>
                        <thead>
                            <tr>
                                <th rowspan="2">SUBJECT<br>CODE</th>
                                <th rowspan="2">DESCRIPTIVE TITLE</th>
                                <th colspan="2">NO. HOURS</th>
                                <th rowspan="2">UNITS</th>
                                <th rowspan="2">PRE-<br>REQUISITES</th>
                                <th rowspan="2">GRADES</th>
                            </tr>
                            <tr>
                                <th>LEC</th>
                                <th>LAB</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($summer as $item)
                                <tr>
                                    <td>{{ $item->display_code }}</td>
                                    <td>
                                        {{ $item->display_title }}
                                        @if($item->isOjt())
                                            <br><span style="font-style:italic;">({{ $item->ojt_hours }} hours)</span>
                                        @endif
                                    </td>
                                    <td class="center">{{ $item->subject->lecture_hours ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->laboratory_hours ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->units ?? '0' }}</td>
                                    <td class="center">{{ $item->subject->prerequisite->subject_code ?? 'None' }}</td>
                                    <td></td>
                                </tr>
                            @endforeach
                            <tr class="total-row">
                                <td colspan="2" class="label">TOTAL</td>
                                <td class="center">{{ $sLecTotal }}</td>
                                <td class="center">{{ $sLabTotal }}</td>
                                <td class="center">{{ $sUnitsTotal }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach

    <div class="summary-wrap">
        <table class="summary">
            <caption>SUMMARY OF UNITS</caption>
            <tr>
                <th></th>
                <th>UNITS</th>
            </tr>
            <tr class="grand">
                <td class="label" style="font-style:normal; font-weight:700;">TOTAL CURRICULUM UNITS</td>
                <td class="val">{{ $grandUnitsTotal }}</td>
            </tr>
        </table>
    </div>

</body>
</html>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty Schedule{{ $academicTerm ? ' — ' . $academicTerm->display_name : '' }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #1a1a1a;
            margin: 0;
            padding: 24px;
            background: #e8ebee;
        }

        .no-print {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 20px;
        }

        .no-print button {
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid #2563eb;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
        }

        .no-print button.secondary {
            background: #fff;
            color: #2563eb;
        }

        /* ─── One printed "sheet" per Faculty member ──────────────── */
        .sheet {
            background: #fff;
            max-width: 900px;
            margin: 0 auto 24px;
            padding: 32px 36px;
            break-after: page;
            page-break-after: always;
            box-shadow: 0 1px 4px rgba(0,0,0,0.12);
        }

        .sheet:last-child {
            break-after: auto;
            page-break-after: auto;
            margin-bottom: 0;
        }

        header.doc-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 3px solid #1a1a1a;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }

        .doc-header .brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .doc-header .brand-mark {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
        }

        .doc-header .brand-mark img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .doc-header .brand-text h2 {
            margin: 0;
            font-size: 13px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .doc-header .brand-text p {
            margin: 1px 0 0;
            font-size: 10.5px;
            color: #666;
            letter-spacing: 0.3px;
        }

        .doc-status {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 5px;
            padding: 4px 10px;
            white-space: nowrap;
        }

        .doc-status .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #d97706;
            margin-right: 5px;
        }

        h1.doc-title {
            text-align: center;
            margin: 4px 0 2px;
            font-size: 20px;
            letter-spacing: 0.3px;
        }

        p.doc-subtitle {
            text-align: center;
            margin: 0 0 14px;
            font-size: 12px;
            color: #555;
        }

        /* ─── Meta strip: College / Faculty / Subjects Assigned ───── */
        .meta-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            background: #f4f6f8;
            border: 1px solid #dde1e6;
            border-radius: 8px;
            padding: 10px 16px;
            margin-bottom: 18px;
        }

        .meta-strip .meta-item .label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #8a8f98;
            margin-bottom: 2px;
        }

        .meta-strip .meta-item .value {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a1a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }

        th, td {
            border: 1px solid #d6dadf;
            padding: 7px 9px;
            text-align: left;
        }

        th {
            background: #1a1a1a;
            color: #fff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.4px;
        }

        tbody tr:nth-child(even) {
            background: #fafbfc;
        }

        td.center, th.center {
            text-align: center;
        }

        .subject-title {
            color: #666;
            font-size: 11px;
        }

        tfoot td {
            font-weight: 800;
            background: #f4f6f8;
            border-top: 2px solid #1a1a1a;
        }

        .empty-note {
            font-size: 13px;
            color: #666;
            text-align: center;
            padding: 30px 0;
        }

        footer.doc-footer {
            margin-top: 22px;
            font-size: 10px;
            color: #888;
            display: flex;
            justify-content: space-between;
            border-top: 1px solid #e2e5e9;
            padding-top: 8px;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .sheet { box-shadow: none; margin: 0; max-width: none; padding: 18px 24px; }
            @page { margin: 1.2cm; }
        }
    </style>
</head>
<body>

    @php
        // Same "minutes since midnight -> 12-hour label" conversion
        // used by block-schedule/print.blade.php — guarded with
        // function_exists() since both print views can render in the
        // same request during local previewing.
        if (! function_exists('formatMinutes')) {
            function formatMinutes($minutes)
            {
                if ($minutes === null) {
                    return null;
                }

                $h24 = intdiv($minutes, 60) % 24;
                $m = $minutes % 60;
                $period = $h24 >= 12 ? 'PM' : 'AM';
                $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

                return sprintf('%d:%02d %s', $h12, $m, $period);
            }
        }
    @endphp

    <div class="no-print">
        <button class="secondary" onclick="window.close()">Close</button>
        <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    @forelse ($facultySchedules as $entry)
        @php
            $faculty = $entry['faculty'];
            $rows = $entry['rows'];
            $totalUnits = collect($rows)->sum('units');
        @endphp
        <div class="sheet">
            <header class="doc-header">
                <div class="brand">
                    <div class="brand-mark">
                        <img src="{{ asset('logo.png') }}" alt="Classly logo">
                    </div>
                    <div class="brand-text">
                        <h2>Professional Academy of the Philippines</h2>
                        <p>Classly — Class Scheduling Management System</p>
                    </div>
                </div>
                <span class="doc-status"><span class="dot"></span>{{ $academicTerm?->status ?? 'Draft' }}</span>
            </header>

            <h1 class="doc-title">Faculty Schedule</h1>
            <p class="doc-subtitle">
                Committed weekly teaching load for this faculty member.
                @if ($academicTerm)
                    &nbsp;·&nbsp;{{ $academicTerm->display_name }}
                @endif
            </p>

            <div class="meta-strip">
                <div class="meta-item">
                    <div class="label">College</div>
                    <div class="value">{{ $department['code'] }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Faculty</div>
                    <div class="value">{{ $faculty->full_name }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Subjects Assigned</div>
                    <div class="value">{{ count($rows) }}</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 12%;">EDP Code</th>
                        <th>Subject</th>
                        <th style="width: 10%;">Block</th>
                        <th class="center" style="width: 7%;">Units</th>
                        <th style="width: 22%;">Day &amp; Time</th>
                        <th style="width: 13%;">Room</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['edp_code'] ?? '—' }}</td>
                            <td>
                                <div>{{ $row['subject_code'] }}</div>
                                <div class="subject-title">{{ $row['descriptive_title'] }}</div>
                            </td>
                            <td>{{ $row['section_code'] ?? '—' }}</td>
                            <td class="center">{{ $row['units'] ?? '—' }}</td>
                            <td>
                                @if (! empty($row['days']))
                                    {{ collect($row['days'])->map(fn ($day) => ucfirst($day))->implode(', ') }} ·
                                    {{ formatMinutes($row['start_minutes']) }} –
                                    {{ formatMinutes($row['end_minutes']) }}
                                @else
                                    Unscheduled
                                @endif
                            </td>
                            <td>{{ $row['room_code'] ?? 'TBA' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;">Total Units — {{ $faculty->full_name }}</td>
                        <td class="center">{{ $totalUnits }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <footer class="doc-footer">
                <span>Generated {{ $generatedAt->format('F j, Y g:i A') }}</span>
                <span>Professional Academy of the Philippines — Classly</span>
            </footer>
        </div>
    @empty
        <div class="sheet">
            <p class="empty-note">No faculty have Teaching Assignments in this department for the current term yet.</p>
        </div>
    @endforelse

</body>
</html>
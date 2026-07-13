<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class List{{ $academicTerm ? ' — ' . $academicTerm->display_name : '' }}</title>
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

        /* ─── One printed "sheet" per Section ─────────────────────── */
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

        /* ─── Header bar: logo + title left, status badge right ──── */
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

        /* ─── Section meta strip: Program / Year / Section ────────── */
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

        .classification-badge {
            display: inline-block;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 2px 7px;
            border-radius: 4px;
        }

        .classification-badge.major {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .classification-badge.minor {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
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

    <div class="no-print">
        <button class="secondary" onclick="window.close()">Close</button>
        <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    @forelse ($sections as $section)
        @php
            $sectionUnits = collect($section['offerings'])->sum(fn ($o) => (int) ($o->units ?? 0));
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
                <span class="doc-status"><span class="dot"></span>Preliminary</span>
            </header>

            <h1 class="doc-title">Class List — Subject Offerings</h1>
            <p class="doc-subtitle">
                Partial list for posting before enrollment. Faculty, Room, and Schedule are not yet assigned.
                @if ($academicTerm)
                    &nbsp;·&nbsp;{{ $academicTerm->display_name }}
                @endif
            </p>

            <div class="meta-strip">
                <div class="meta-item">
                    <div class="label">Program</div>
                    <div class="value">{{ $section['program_code'] ?? '—' }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Year Level</div>
                    <div class="value">{{ $section['year_level'] ? 'Year ' . $section['year_level'] : '—' }}</div>
                </div>
                <div class="meta-item">
                    <div class="label">Section</div>
                    <div class="value">{{ $section['section_code'] }}</div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width: 13%;">EDP Code</th>
                        <th style="width: 13%;">Subject Code</th>
                        <th>Descriptive Title</th>
                        <th class="center" style="width: 8%;">Units</th>
                        <th class="center" style="width: 8%;">Hours</th>
                        <th style="width: 14%;">Classification</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['offerings'] as $offering)
                        <tr>
                            <td>{{ $offering->edp_code }}</td>
                            <td>{{ $offering->subject?->subject_code }}</td>
                            <td>{{ $offering->subject?->descriptive_title }}</td>
                            <td class="center">{{ $offering->units ?? '—' }}</td>
                            <td class="center">{{ $offering->hours ?? '—' }}</td>
                            <td>
                                @if ($offering->classification)
                                    <span class="classification-badge {{ strtolower($offering->classification) }}">
                                        {{ $offering->classification }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" style="text-align: right;">Total Units — {{ $section['section_code'] }}</td>
                        <td class="center">{{ $sectionUnits }}</td>
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
            <p class="empty-note">No Subject Offerings match the current filters.</p>
        </div>
    @endforelse

</body>
</html>
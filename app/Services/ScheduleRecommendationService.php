<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Room;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates "here's a better option" suggestions once
 * ScheduleValidationService has found a conflict. Reads from the same
 * inputs the Greedy Scheduler and Master Grid already use (Faculty,
 * Rooms, Room/Faculty preference pivots, the in-memory preview) — it
 * never invents data of its own and never writes anything.
 *
 * Kept entirely separate from ScheduleValidationService on purpose:
 * validation answers "is this allowed?", recommendation answers
 * "what would be allowed instead?" — a future optimizer only needs the
 * first; a human reviewing conflicts wants the second too.
 */
class ScheduleRecommendationService
{
    /**
     * Standard day-combinations for a 2x/3x-per-week subject — must
     * stay identical to GreedyScheduleService::DAY_COMBOS_2X/3X. That
     * class can't be depended on directly here (it's the scheduling
     * engine, not a shared lookup table), so this is a deliberate,
     * cross-referenced duplicate: a suggested time for a 2x subject
     * must land on one of the SAME standard pairings the Greedy
     * Scheduler itself is allowed to place, or the suggestion could
     * recommend a combo the algorithm would never have produced.
     * Keep both lists in sync if the spec's pairings ever change.
     */
    private const DAY_COMBOS_2X = [
        ['monday', 'wednesday'],
        ['tuesday', 'thursday'],
        ['wednesday', 'friday'],
        ['monday', 'thursday'],
    ];

    private const DAY_COMBOS_3X = [
        ['monday', 'wednesday', 'friday'],
        ['tuesday', 'thursday', 'saturday'],
    ];

    public function __construct(
        private readonly ScheduleValidationService $validator
    ) {
    }

    /**
     * @param  array  $block  The conflicting block (as edited).
     * @param  Collection<int,array>  $allBlocks  Every block in the
     *         current preview, including $block.
     * @return array{faculty: array, rooms: array, times: array}
     */
    public function recommend(array $block, Collection $allBlocks, AcademicTerm $term): array
    {
        // Preview + already-saved schedules, merged — see
        // ScheduleValidationService::allKnownBlocksForTerm(). Every
        // suggestion below is checked against this full picture, not
        // just the in-memory preview batch, so a room/faculty/time
        // already spoken for by a PREVIOUSLY saved class (outside this
        // batch) can never be suggested as if it were free.
        $known = $this->validator->allKnownBlocksForTerm($allBlocks, $term);

        return [
            'faculty' => $this->suggestFaculty($block, $known),
            'rooms' => $this->suggestRooms($block, $known),
            'times' => $this->suggestTimes($block, $known, $term),
            // "What if this met more often, for less time each?" — see
            // suggestMeetingSplits() docblock. Only ever proposes MORE
            // frequent, SHORTER meetings than the block's current
            // meetings_per_week, never fewer/longer.
            'meeting_splits' => $this->suggestMeetingSplits($block, $known, $term),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Faculty
    |--------------------------------------------------------------------------
    |
    | Sorted by: 1) lowest current teaching load, 2) same department as
    | the offering's program, 3) earliest availability (already
    | filtered — only free faculty are suggested at all).
    */
    private function suggestFaculty(array $block, Collection $allBlocks, int $limit = 3): array
    {
        $departmentId = $block['department_id'] ?? null;

        $candidates = Faculty::where('status', true)
            ->when($block['classification'] ?? null, function ($query, $classification) use ($departmentId) {
                if ($classification === 'Major') {
                    // Major subjects need a faculty scoped to teach
                    // major subjects in this department (Departmental
                    // or Cross Department, same department) or already
                    // department-agnostic Cross Department faculty.
                    $query->where(function ($q) use ($departmentId) {
                        $q->whereIn('faculty_scope', ['departmental', 'cross_department'])
                            ->where(function ($inner) use ($departmentId) {
                                $inner->whereNull('department_id')->orWhere('department_id', $departmentId);
                            });
                    });
                } else {
                    // Minor subjects: General Education faculty, or
                    // Cross Department faculty (minors are unrestricted
                    // for them).
                    $query->where(function ($q) use ($departmentId) {
                        $q->where('faculty_scope', 'general')
                            ->orWhere(function ($inner) use ($departmentId) {
                                $inner->where('faculty_scope', 'cross_department')
                                    ->where(function ($d) use ($departmentId) {
                                        $d->whereNull('department_id')->orWhere('department_id', $departmentId);
                                    });
                            });
                    });
                }
            })
            ->get();

        $loadByFaculty = $allBlocks
            ->whereNotNull('faculty_id')
            ->unique('subject_offering_id')
            ->groupBy('faculty_id')
            ->map(fn ($rows) => $rows->sum(fn ($b) => (int) ($b['units'] ?? 0)));

        return $candidates
            ->filter(fn (Faculty $faculty) => $faculty->id !== ($block['faculty_id'] ?? null))
            ->filter(function (Faculty $faculty) use ($block, $allBlocks) {
                // Must be free at the block's current day/time.
                return ! $this->hasOverlap($allBlocks, $block, 'faculty_id', $faculty->id);
            })
            ->map(function (Faculty $faculty) use ($loadByFaculty, $departmentId) {
                $load = (int) ($loadByFaculty[$faculty->id] ?? 0);

                return [
                    'faculty_id' => $faculty->id,
                    'full_name' => $faculty->full_name,
                    'department_id' => $faculty->department_id,
                    'current_load' => $load,
                    // effective_max_units (base max_units + any APPROVED
                    // Faculty Load Overload) — matches the cap
                    // TeachingAssignmentService and GreedyScheduleService
                    // both enforce, so this suggestion label never shows a
                    // faculty member as "over" a cap they've actually been
                    // approved to exceed.
                    'max_units' => $faculty->effective_max_units,
                    'same_department' => $faculty->department_id === $departmentId,
                    'label' => "{$faculty->full_name} — Current Load: {$load}/{$faculty->effective_max_units} units",
                ];
            })
            ->sort(function ($a, $b) {
                return [$a['current_load'], $a['same_department'] ? 0 : 1]
                    <=> [$b['current_load'], $b['same_department'] ? 0 : 1];
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Rooms
    |--------------------------------------------------------------------------
    |
    | Sorted by: preferred room first, then room type match, capacity
    | fit, and availability at the block's current day/time.
    */
    private function suggestRooms(array $block, Collection $allBlocks, int $limit = 3): array
    {
        $preferredRoomId = DB::table('room_subject_offering')
            ->where('subject_offering_id', $block['subject_offering_id'])
            ->value('room_id');

        $programCode = $block['program_code'] ?? null;

        $candidates = Room::schedulable()
            ->with('roomGroups')
            ->get()
            ->filter(function (Room $room) use ($block, $programCode) {
                if ($room->id === ($block['room_id'] ?? null)) {
                    return false;
                }

                if (! empty($block['room_type']) && $room->room_type !== $block['room_type']) {
                    return false;
                }

                $allowed = in_array('General', $room->room_group_codes, true)
                    || ($programCode && in_array($programCode, $room->room_group_codes, true));

                return $allowed;
            })
            ->filter(fn (Room $room) => ! $this->hasOverlap($allBlocks, $block, 'room_id', $room->id))
            ->values();

        return $candidates
            ->map(fn (Room $room) => [
                'room_id' => $room->id,
                'room_code' => $room->room_code,
                'room_type' => $room->room_type,
                'capacity' => $room->capacity,
                'is_preferred' => $room->id === $preferredRoomId,
                'label' => $room->id === $preferredRoomId
                    ? "{$room->room_code} — Preferred, {$room->room_type}, Available"
                    : "{$room->room_code} — {$room->room_type}, Available",
            ])
            ->sort(fn ($a, $b) => ($a['is_preferred'] ? 0 : 1) <=> ($b['is_preferred'] ? 0 : 1))
            ->take($limit)
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    |
    | Walks the term's working days/start-times (same grid the Greedy
    | Scheduler builds) and returns the nearest slots where THIS SAME
    | faculty + room + section are all simultaneously free.
    |
    | meetings_per_week-aware: a subject that meets 2x/3x a week must
    | keep the SAME faculty/room/time across every one of its meeting
    | days (see GreedyScheduleService's "Multi-meeting subjects"
    | docblock) — a suggestion naming only one day would be incomplete
    | and, if applied as-is, would leave the subject's other meeting
    | day(s) sitting at whatever time they were at before. So for a
    | 2x/3x subject this returns a full day-combo (e.g. ['wednesday',
    | 'thursday']) per suggestion, built from the same standard
    | pairings the Greedy Scheduler itself uses (DAY_COMBOS_2X/3X
    | above) — and a suggestion is only ever offered if EVERY day in
    | the combo is simultaneously free for that faculty/room/section,
    | never just the first one.
    |
    | Diversified one-suggestion-per-combo (mirroring the old one-
    | suggestion-per-day cap): the first combo/day that has a free
    | slot contributes exactly one suggestion, then the search moves
    | on to the next combo/day, so results spread out instead of
    | stacking multiple near-identical times from the same combo.
    */
    private function suggestTimes(array $block, Collection $allBlocks, AcademicTerm $term, int $limit = 2): array
    {
        $duration = ($block['end_minutes'] ?? null) !== null && ($block['start_minutes'] ?? null) !== null
            ? $block['end_minutes'] - $block['start_minutes']
            : (int) ($block['hours'] ?? 0) * 60;

        if ($duration <= 0) {
            return [];
        }

        $dayFields = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $workingDays = array_values(array_filter($dayFields, fn ($f) => (bool) $term->{$f}));

        [$schoolStart, $schoolEnd] = $this->validator->schoolHours($term);
        [$lunchStart, $lunchEnd] = $this->validator->lunchWindow($term);
        $interval = $term->time_interval ?: 30;

        if ($schoolStart === null || $schoolEnd === null) {
            return [];
        }

        $meetings = (int) ($block['meetings_per_week'] ?? 1) ?: 1;
        $combos = $this->resolveDayCombos($meetings, $workingDays, $block['day'] ?? null);

        if (empty($combos)) {
            return [];
        }

        $suggestions = [];

        foreach ($combos as $combo) {
            // At most one suggestion per combo — as soon as we find
            // the first slot where every day in this combo is free,
            // stop scanning this combo and move on to the next one.
            for ($start = $schoolStart; $start + $duration <= $schoolEnd; $start += $interval) {
                $end = $start + $duration;

                if ($lunchStart !== null && $lunchEnd !== null && $start < $lunchEnd && $end > $lunchStart) {
                    continue;
                }

                // Skip the exact slot this block is already sitting
                // on (only possible to exactly match for a 1x/week
                // subject, where the combo is a single day).
                if ($combo === [$block['day'] ?? null] && $start === $block['start_minutes']) {
                    continue;
                }

                $comboIsFree = true;

                foreach ($combo as $day) {
                    $candidate = array_merge($block, [
                        'day' => $day,
                        'start_minutes' => $start,
                        'end_minutes' => $end,
                    ]);

                    $dayIsFree = ! $this->hasOverlap($allBlocks, $candidate, 'faculty_id', $block['faculty_id'] ?? null, $day, $start, $end)
                        && ! $this->hasOverlap($allBlocks, $candidate, 'room_id', $block['room_id'] ?? null, $day, $start, $end)
                        && ! $this->hasOverlap($allBlocks, $candidate, 'section_id', $block['section_id'] ?? null, $day, $start, $end);

                    if (! $dayIsFree) {
                        $comboIsFree = false;
                        break;
                    }
                }

                if (! $comboIsFree) {
                    continue;
                }

                $suggestions[] = [
                    'days' => $combo,
                    // Kept for backward compatibility with anything
                    // still reading a single `day` — always the
                    // combo's first day (for a 1x subject, its only
                    // day).
                    'day' => $combo[0],
                    'start_minutes' => $start,
                    'end_minutes' => $end,
                    'label' => $this->comboLabel($combo) . ' ' . $this->validator->label($start) . '–' . $this->validator->label($end),
                ];

                // Found this combo's one slot — stop scanning it and
                // move on to the next combo.
                break;
            }

            if (count($suggestions) >= $limit) {
                return $suggestions;
            }
        }

        return $suggestions;
    }

    /*
    |--------------------------------------------------------------------------
    | Meeting Splits ("meet more often, for less time each")
    |--------------------------------------------------------------------------
    |
    | Example this exists for: a 4-hour, 1x/week subject can't find a
    | single 4-hour block anywhere, but Wed 3–5PM (2 hrs) IS free. As a
    | single-block subject that 2-hour gap is useless — but if the
    | subject instead met 2x/week at 2 hrs each, that same gap (plus a
    | matching gap on its paired day) becomes a valid placement.
    |
    | This only ever proposes going from the block's CURRENT
    | meetings_per_week to a HIGHER one (never lower — we don't
    | recommend consolidating shorter meetings into one longer block,
    | since that's a curriculum/Session Settings decision, not a
    | conflict-resolution one). Candidates are capped at 3x/week
    | because that's the highest meetings_per_week SessionSettingsService
    | and GreedyScheduleService support (see
    | SessionSettingsService::ALLOWED_MEETINGS_PER_WEEK).
    |
    | Each candidate's per-meeting duration is total hours*60 divided
    | evenly by the candidate meeting count, same rounding
    | GreedyScheduleService::generateForSection() uses — so a suggestion
    | here, if accepted, produces exactly the placement the Greedy
    | Scheduler itself would have produced had Session Settings already
    | been saved with that meetings_per_week.
    |
    | This does NOT persist anything. Accepting a suggestion still goes
    | through the normal path: update the offering's meetings_per_week
    | (Session Settings, or SubjectOffering::update() directly), then
    | re-place the block — same as any other recommendation here.
    */
    private function suggestMeetingSplits(array $block, Collection $allBlocks, AcademicTerm $term, int $maxMeetings = 3): array
    {
        $currentMeetings = (int) ($block['meetings_per_week'] ?? 1) ?: 1;

        $totalMinutes = ($block['hours'] ?? null) !== null
            ? (int) $block['hours'] * 60
            : (($block['end_minutes'] ?? 0) - ($block['start_minutes'] ?? 0)) * $currentMeetings;

        if ($totalMinutes <= 0 || $currentMeetings >= $maxMeetings) {
            return [];
        }

        $suggestions = [];

        for ($candidateMeetings = $currentMeetings + 1; $candidateMeetings <= $maxMeetings; $candidateMeetings++) {
            $duration = (int) round($totalMinutes / $candidateMeetings);

            if ($duration <= 0) {
                continue;
            }

            $slot = $this->findFirstFreeSlot($block, $allBlocks, $term, $candidateMeetings, $duration);

            if (! $slot) {
                continue;
            }

            $suggestions[] = array_merge($slot, [
                'meetings_per_week' => $candidateMeetings,
                'hours_per_meeting' => round($duration / 60, 2),
                'message' => "No {$this->hoursLabel($totalMinutes)} slot is free — switching this subject to {$candidateMeetings}x/week ({$this->hoursLabel($duration)} each) fits at "
                    . $this->comboLabel($slot['days']) . ' ' . $this->validator->label($slot['start_minutes']) . '–' . $this->validator->label($slot['end_minutes']) . '.',
            ]);
        }

        return $suggestions;
    }

    /**
     * Core slot search shared conceptually with suggestTimes() — same
     * grid, same lunch/working-day rules, same "every day in the combo
     * must be simultaneously free" logic — but parameterized on
     * meetings/duration so it can be reused for a DIFFERENT
     * meetings_per_week than the block currently has (suggestTimes()
     * itself intentionally stays untouched/duration-locked to the
     * block's existing config, since that's still the primary,
     * same-config suggestion list).
     */
    private function findFirstFreeSlot(array $block, Collection $allBlocks, AcademicTerm $term, int $meetings, int $duration): ?array
    {
        $dayFields = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $workingDays = array_values(array_filter($dayFields, fn ($f) => (bool) $term->{$f}));

        [$schoolStart, $schoolEnd] = $this->validator->schoolHours($term);
        [$lunchStart, $lunchEnd] = $this->validator->lunchWindow($term);
        $interval = $term->time_interval ?: 30;

        if ($schoolStart === null || $schoolEnd === null) {
            return null;
        }

        $combos = $this->resolveDayCombos($meetings, $workingDays, $block['day'] ?? null);

        foreach ($combos as $combo) {
            for ($start = $schoolStart; $start + $duration <= $schoolEnd; $start += $interval) {
                $end = $start + $duration;

                if ($lunchStart !== null && $lunchEnd !== null && $start < $lunchEnd && $end > $lunchStart) {
                    continue;
                }

                $comboIsFree = true;

                foreach ($combo as $day) {
                    $dayIsFree = ! $this->hasOverlap($allBlocks, $block, 'faculty_id', $block['faculty_id'] ?? null, $day, $start, $end)
                        && ! $this->hasOverlap($allBlocks, $block, 'room_id', $block['room_id'] ?? null, $day, $start, $end)
                        && ! $this->hasOverlap($allBlocks, $block, 'section_id', $block['section_id'] ?? null, $day, $start, $end);

                    if (! $dayIsFree) {
                        $comboIsFree = false;
                        break;
                    }
                }

                if (! $comboIsFree) {
                    continue;
                }

                return [
                    'days' => $combo,
                    'day' => $combo[0],
                    'start_minutes' => $start,
                    'end_minutes' => $end,
                ];
            }
        }

        return null;
    }

    /** "4 hrs" / "2.5 hrs" — used in meeting-split suggestion messages. */
    private function hoursLabel(int $minutes): string
    {
        $hours = round($minutes / 60, 2);

        return rtrim(rtrim((string) $hours, '0'), '.') . ' hrs';
    }

    /**
     * Every standard day-combination for $meetings/week that fits
     * within this term's working days — mirrors GreedyScheduleService::
     * resolveDayCombos() exactly (same fixed pairings, same "every day
     * in the combo must be a working day" filter). Rotated so combos
     * containing the block's current day are tried first, purely so a
     * conflicted block's suggestions tend to stay close to whichever
     * days it's already on rather than jumping to a totally unrelated
     * pairing first.
     *
     * @return array<int, array<int, string>>
     */
    private function resolveDayCombos(int $meetings, array $workingDays, ?string $preferredDay): array
    {
        if ($meetings <= 1) {
            $days = $workingDays;

            if ($preferredDay && ($index = array_search($preferredDay, $days, true)) !== false) {
                $days = array_merge([$preferredDay], array_slice($days, 0, $index), array_slice($days, $index + 1));
            }

            return array_map(fn ($day) => [$day], $days);
        }

        $fixed = $meetings === 3 ? self::DAY_COMBOS_3X : self::DAY_COMBOS_2X;

        $combos = array_values(array_filter(
            $fixed,
            fn ($combo) => empty(array_diff($combo, $workingDays))
        ));

        if ($preferredDay) {
            usort($combos, function ($a, $b) use ($preferredDay) {
                $aHas = in_array($preferredDay, $a, true) ? 0 : 1;
                $bHas = in_array($preferredDay, $b, true) ? 0 : 1;

                return $aHas <=> $bHas;
            });
        }

        return $combos;
    }

    /**
     * "Wednesday" for a single-day combo, "Wednesday & Thursday" for a
     * 2x combo, "Monday, Wednesday & Friday" for a 3x combo.
     */
    private function comboLabel(array $combo): string
    {
        $names = array_map(fn ($day) => ucfirst($day), $combo);

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names) . ' & ' . $last;
    }

    /**
     * Whether any OTHER block in $allBlocks shares $field's value with
     * $matchValue and overlaps the given day/time window.
     */
    private function hasOverlap(
        Collection $allBlocks,
        array $block,
        string $field,
        ?int $matchValue,
        ?string $day = null,
        ?int $start = null,
        ?int $end = null
    ): bool {
        if (! $matchValue) {
            return false;
        }

        $day ??= $block['day'];
        $start ??= $block['start_minutes'];
        $end ??= $block['end_minutes'];

        return $allBlocks
            ->reject(fn ($b) => $b['subject_offering_id'] === $block['subject_offering_id'])
            ->filter(fn ($b) => ($b[$field] ?? null) === $matchValue)
            ->filter(fn ($b) => ($b['day'] ?? null) === $day)
            ->filter(fn ($b) => $start < ($b['end_minutes'] ?? -1) && $end > ($b['start_minutes'] ?? PHP_INT_MAX))
            ->isNotEmpty();
    }
}
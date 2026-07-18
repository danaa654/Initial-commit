<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Faculty;
use App\Models\Room;
use App\Models\Schedule;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "is this schedule block valid?" — used by
 * both the Interactive Review step (validating one edited block against
 * the rest of the in-memory preview) and Save Schedule (re-validating
 * every block right before the transaction commits). Nothing here
 * writes anything; every method is a pure read/check so future
 * optimization algorithms (Genetic Algorithm, Simulated Annealing,
 * etc.) can call the exact same rules without modification.
 *
 * A "block" is a plain array shaped like GreedyScheduleService's
 * presentBlock() output plus whatever the Registrar edited:
 *
 *   subject_offering_id, section_id, program_id, program_code,
 *   room_type, classification, units, hours,
 *   faculty_id, faculty_name,
 *   room_id, room_code,
 *   day (lowercase field: 'monday'..'sunday'),
 *   start_minutes, end_minutes
 */
class ScheduleValidationService
{
    public const TYPE_FACULTY = 'faculty_conflict';
    public const TYPE_ROOM = 'room_conflict';
    public const TYPE_SECTION = 'section_conflict';
    public const TYPE_HOURS = 'outside_school_hours';
    public const TYPE_LUNCH = 'lunch_break_violation';
    public const TYPE_DAY = 'non_working_day';
    public const TYPE_ROOM_TYPE = 'invalid_room_type';
    public const TYPE_ROOM_PROGRAM = 'room_not_allowed_for_program';
    public const TYPE_OVERLOAD = 'faculty_overload';

    /**
     * Validates one block against every other block currently in play
     * (the rest of the in-memory preview, plus anything already saved
     * to the `schedules` table for this term — excluding the block's
     * own subject_offering_id in both sets, since it can't conflict
     * with its own prior state).
     *
     * @param  array  $block  The (possibly just-edited) block to check.
     * @param  Collection<int,array>  $allBlocks  Every other block in the
     *         current preview, INCLUDING $block itself (it is skipped
     *         by subject_offering_id internally).
     * @param  AcademicTerm  $term
     * @return array{conflicts: array<int,array>, warnings: array<int,array>}
     */
    public function validateBlock(array $block, Collection $allBlocks, AcademicTerm $term): array
    {
        // Every key the rest of this method (and overlappingOthers()
        // below) touches, defaulted to null/empty up front. The
        // frontend builds several DIFFERENT shapes of "block" — a
        // fresh drag-and-drop placement, a Greedy preview row, an
        // already-saved Schedule row being re-edited — and it has
        // twice now missed a field one of those shapes needs (first
        // section_id, this time something else). Rather than patch
        // this one field at a time forever, every key is guaranteed
        // to exist here: a genuinely missing field now just reads as
        // null (and is treated as "not set" by every check below,
        // same as it always was when the key WAS present but empty)
        // instead of throwing an "Undefined array key" ErrorException
        // that surfaces to the person as the unhelpful, generic
        // "Could not check for conflicts. Please try again."
        $block = array_merge([
            'subject_offering_id' => null,
            'section_id' => null,
            'program_id' => null,
            'program_code' => null,
            'room_type' => null,
            'classification' => null,
            'units' => null,
            'hours' => null,
            'faculty_id' => null,
            'faculty_name' => null,
            'room_id' => null,
            'room_code' => null,
            'room_override' => false,
            'day' => null,
            'start_minutes' => null,
            'end_minutes' => null,
        ], $block);

        $conflicts = [];
        $warnings = [];

        if (! $block['day'] || $block['start_minutes'] === null || $block['end_minutes'] === null) {
            $conflicts[] = $this->conflict(self::TYPE_HOURS, $block, null, 'This block has no day/time set yet.');

            return ['conflicts' => $conflicts, 'warnings' => $warnings];
        }

        // 1. Non-working day
        if (! $this->isWorkingDay($term, $block['day'])) {
            $conflicts[] = $this->conflict(
                self::TYPE_DAY,
                $block,
                null,
                ucfirst($block['day']) . ' is not a working day for ' . $term->display_name . '.'
            );
        }

        // 2. Outside school hours
        [$schoolStart, $schoolEnd] = $this->schoolHours($term);

        if ($schoolStart !== null && $schoolEnd !== null) {
            if ($block['start_minutes'] < $schoolStart || $block['end_minutes'] > $schoolEnd) {
                $conflicts[] = $this->conflict(
                    self::TYPE_HOURS,
                    $block,
                    null,
                    'This block falls outside school hours (' . $this->label($schoolStart) . ' – ' . $this->label($schoolEnd) . ').'
                );
            }
        }

        // 3. Lunch break violation
        [$lunchStart, $lunchEnd] = $this->lunchWindow($term);

        if ($lunchStart !== null && $lunchEnd !== null) {
            $overlapsLunch = $block['start_minutes'] < $lunchEnd && $block['end_minutes'] > $lunchStart;

            if ($overlapsLunch) {
                $conflicts[] = $this->conflict(
                    self::TYPE_LUNCH,
                    $block,
                    null,
                    'This block overlaps the Lunch Break (' . $this->label($lunchStart) . ' – ' . $this->label($lunchEnd) . ').'
                );
            }
        }

        // 4. Invalid room type, and 4b. Room not allowed for this
        // program — both keyed off the same Room lookup, since a room
        // can fail either check independently of the other (Ground
        // Zero and a CCS lecture hall can both be "Laboratory" rooms
        // while only one of them is actually allowed to host BSIT).
        $room = null;

        if (! empty($block['room_code']) || ! empty($block['room_id'])) {
            $room = Room::where('room_code', $block['room_code'] ?? null)->first()
                ?? ($block['room_id'] ? Room::find($block['room_id']) : null);
        }

        if ($room) {
            if (! empty($block['room_type']) && $room->room_type !== $block['room_type']) {
                $reason = "{$room->room_code} is a {$room->room_type} room, but this subject requires {$block['room_type']}.";

                // Same Room Eligibility Override as the Allowed
                // Programs check just below — an explicit, per-edit
                // opt-in (EditScheduleModal's "Override Eligibility"
                // checkbox on the Room field), downgraded to a warning
                // rather than skipped outright so the exception stays
                // visible on the block instead of silently vanishing.
                if ($block['room_override']) {
                    $warnings[] = $this->conflict(self::TYPE_ROOM_TYPE, $block, null, "{$reason} Allowed via Override Eligibility.");
                } else {
                    $conflicts[] = $this->conflict(self::TYPE_ROOM_TYPE, $block, null, $reason);
                }
            }

            // A room's Allowed Programs (room_group_room — see
            // Room::roomGroups()) is either "General" (every program),
            // one or more specific program codes ("Shared"), or exactly
            // one ("Exclusive") — completely independent of Room Type.
            // Ground Zero being a Laboratory doesn't make it available
            // to every OTHER Laboratory subject; it only ever hosts
            // whatever program(s) its own Allowed list names.
            if (! empty($block['program_code'])) {
                $allowedCodes = $room->room_group_codes;

                $allowed = in_array('General', $allowedCodes, true)
                    || in_array($block['program_code'], $allowedCodes, true);

                if (! $allowed) {
                    $reservedFor = $allowedCodes ? implode(', ', $allowedCodes) : 'a different program';

                    // Room Eligibility Override (EditScheduleModal's
                    // "Override Eligibility" checkbox on the Room
                    // field) is an explicit, per-edit opt-in for a
                    // legitimate cross-college room share — e.g. an
                    // SHTM HM/TM section running a subject like ITE in
                    // one of CCS's computer labs. Rather than block it
                    // as a conflict the way an unapproved choice would
                    // be, an overridden pick is downgraded to a
                    // warning: still visible on the block (and still
                    // recorded, unlike Faculty's override which has no
                    // server-side signal at all — see EditScheduleModal's
                    // docblock), but no longer something Apply/Save
                    // treats as a reason to refuse the block.
                    if ($block['room_override']) {
                        $warnings[] = $this->conflict(
                            self::TYPE_ROOM_PROGRAM,
                            $block,
                            null,
                            "{$room->room_code} is not normally allowed for {$block['program_code']} (reserved for {$reservedFor}) — allowed via Override Eligibility."
                        );
                    } else {
                        $conflicts[] = $this->conflict(
                            self::TYPE_ROOM_PROGRAM,
                            $block,
                            null,
                            "{$room->room_code} is not allowed for {$block['program_code']} — it's reserved for {$reservedFor}."
                        );
                    }
                }
            }
        }

        // Everything else needs something to compare against.
        $others = $this->overlappingOthers($block, $allBlocks, $term);

        foreach ($others as $other) {
            if ($block['faculty_id'] && ($other['faculty_id'] ?? null) === $block['faculty_id']) {
                $conflicts[] = $this->conflict(self::TYPE_FACULTY, $block, $other, $this->facultyName($block) . ' is already teaching at this time.');
            }

            if ($block['room_id'] && ($other['room_id'] ?? null) === $block['room_id']) {
                $conflicts[] = $this->conflict(self::TYPE_ROOM, $block, $other, ($block['room_code'] ?? 'This room') . ' is already occupied at this time.');
            }

            if ($block['section_id'] && ($other['section_id'] ?? null) === $block['section_id']) {
                $conflicts[] = $this->conflict(self::TYPE_SECTION, $block, $other, 'This section already has another class scheduled at this time.');
            }
        }

        // 8. Faculty overload — a hard conflict, same as Faculty
        // Loading's assertWithinMaxUnits(). Master Grid used to only
        // WARN here and still let the block save, which meant a
        // faculty member's effective cap could be silently blown past
        // by a drag-and-drop/Edit Schedule change even though Faculty
        // Loading itself would have refused the exact same assignment.
        // Blocking it here closes that gap: whichever workspace a
        // faculty member's load is changed from, the same cap applies.
        if ($block['faculty_id']) {
            $faculty = Faculty::find($block['faculty_id']);

            // effective_max_units (base max_units + any APPROVED Faculty
            // Load Overload) — same cap TeachingAssignmentService and
            // GreedyScheduleService enforce. Comparing against the raw
            // max_units column here would raise a false "over their max
            // load" conflict for a faculty member who has been
            // legitimately approved to carry more (see
            // FacultyLoadOverloadService).
            if ($faculty && $faculty->effective_max_units) {
                $loadedUnits = $allBlocks
                    ->where('faculty_id', $block['faculty_id'])
                    ->unique('subject_offering_id')
                    ->sum(fn ($b) => (int) ($b['units'] ?? 0));

                if ($loadedUnits > $faculty->effective_max_units) {
                    $conflicts[] = $this->conflict(
                        self::TYPE_OVERLOAD,
                        $block,
                        null,
                        "{$faculty->full_name} would be loaded {$loadedUnits}/{$faculty->effective_max_units} units — over their max load. Request a Faculty Load Overload or choose a different faculty member."
                    );
                }
            }
        }

        return ['conflicts' => $conflicts, 'warnings' => $warnings];
    }

    /**
     * Validates every block in a full preview set — used right before
     * Save Schedule commits. Returns subject_offering_id => conflicts
     * for every block that has at least one conflict; an empty array
     * means the whole set is safe to save.
     *
     * A subject meeting 2x/3x a week now contributes MULTIPLE blocks
     * here (one per meeting day, same subject_offering_id — see
     * GreedyScheduleService's "Multi-meeting subjects" docblock), so
     * this merges conflicts from every one of a subject's meeting rows
     * into that same key rather than letting a later meeting's result
     * silently overwrite an earlier meeting's conflicts. The frontend
     * still only needs subject_offering_id to highlight the right
     * blocks (see Index.vue's `Object.keys(conflicts).map(Number)`),
     * so the key shape here is unchanged — only same-key entries now
     * accumulate instead of replacing.
     *
     * @param  Collection<int,array>  $blocks
     * @return array<int,array>
     */
    public function validateAll(Collection $blocks, AcademicTerm $term): array
    {
        $result = [];

        foreach ($blocks as $block) {
            $outcome = $this->validateBlock($block, $blocks, $term);

            if (! empty($outcome['conflicts'])) {
                $offeringId = $block['subject_offering_id'];

                $result[$offeringId] = array_merge($result[$offeringId] ?? [], $outcome['conflicts']);
            }
        }

        return $result;
    }

    /**
     * Every block the term actually knows about: the in-memory preview
     * PLUS whatever's already committed to `schedules` for offerings
     * NOT in that preview. This is the exact same "preview + saved"
     * merge overlappingOthers() below does per-block for conflict
     * detection — pulled out as a public, reusable method because
     * ScheduleRecommendationService needs the identical merged set.
     * Without this, a suggestion could recommend a room/faculty/time
     * that's actually already taken by a PREVIOUSLY saved class simply
     * because that class isn't part of the current preview batch — the
     * validator would still correctly reject it, but the recommender
     * would have no idea it was ever a bad idea to suggest in the
     * first place.
     *
     * Preview rows win over a saved row for the same
     * subject_offering_id — a block actively being edited/generated
     * always represents the newest intent for that offering, so its
     * saved counterpart (about to be overwritten by Save Schedule
     * anyway) is dropped rather than kept alongside it.
     */
    public function allKnownBlocksForTerm(Collection $previewBlocks, AcademicTerm $term): Collection
    {
        $saved = Schedule::forTerm($term->id)
            ->get()
            ->map(fn (Schedule $s) => [
                'subject_offering_id' => $s->subject_offering_id,
                'faculty_id' => $s->faculty_id,
                'faculty_name' => $s->faculty?->full_name,
                'room_id' => $s->room_id,
                'room_code' => $s->room?->room_code,
                'section_id' => $s->subjectOffering?->section_id,
                'section_code' => $s->subjectOffering?->section?->section_code,
                'subject_code' => $s->subjectOffering?->subject?->subject_code,
                'units' => $s->subjectOffering?->subject?->units ?? 0,
                'day' => $s->day,
                'start_minutes' => $s->start_minutes,
                'end_minutes' => $s->end_minutes,
            ]);

        $previewOfferingIds = $previewBlocks->pluck('subject_offering_id')->all();

        $savedNotInPreview = $saved->reject(
            fn ($row) => in_array($row['subject_offering_id'], $previewOfferingIds, true)
        );

        return $previewBlocks->merge($savedNotInPreview)->values();
    }

    /*
    |--------------------------------------------------------------------------
    | Shared helpers (also used by ScheduleRecommendationService)
    |--------------------------------------------------------------------------
    */

    public function isWorkingDay(AcademicTerm $term, string $dayField): bool
    {
        return (bool) ($term->{$dayField} ?? false);
    }

    /** @return array{0:?int,1:?int} */
    public function schoolHours(AcademicTerm $term): array
    {
        return [$this->toMinutes($term->school_start_time), $this->toMinutes($term->school_end_time)];
    }

    /** @return array{0:?int,1:?int} */
    public function lunchWindow(AcademicTerm $term): array
    {
        return [$this->toMinutes($term->lunch_start_time), $this->toMinutes($term->lunch_end_time)];
    }

    /**
     * AcademicTerm casts these as 'datetime:H:i' — that format suffix
     * only controls array/JSON serialization, NOT plain PHP attribute
     * access. `$term->school_start_time` actually hands back a raw
     * Carbon instance. The previous (string) cast here just invoked
     * Carbon's default __toString ("Y-m-d H:i:s"), which still passed
     * the str_contains(':') check, then got shredded by the naive
     * explode(':', ...) into garbage hour/minute values (e.g.
     * hours=2026 from the year). That silently broke every conflict/
     * hours/lunch check this service does. Now it reads the Carbon
     * instance directly via format(), and only falls back to a regex
     * pull of the trailing H:i(:s) segment for genuine strings.
     */
    public function toMinutes($value): ?int
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return ((int) $value->format('H')) * 60 + (int) $value->format('i');
        }

        $value = (string) $value;

        if (preg_match('/(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $value, $matches)) {
            return ((int) $matches[1]) * 60 + (int) $matches[2];
        }

        return null;
    }

    public function label(int $minutes): string
    {
        $h24 = intdiv($minutes, 60) % 24;
        $m = $minutes % 60;
        $period = $h24 >= 12 ? 'PM' : 'AM';
        $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

        return sprintf('%d:%02d %s', $h12, $m, $period);
    }

    /**
     * Blocks (from the in-memory preview + already-saved Schedules for
     * this term) that overlap $block on the same day, excluding
     * $block's own subject_offering_id.
     *
     * @return Collection<int,array>
     */
    private function overlappingOthers(array $block, Collection $allBlocks, AcademicTerm $term): Collection
    {
        $preview = $allBlocks
            ->reject(fn ($b) => ($b['subject_offering_id'] ?? null) === $block['subject_offering_id'])
            ->filter(fn ($b) => ($b['day'] ?? null) === $block['day'])
            ->filter(fn ($b) => $this->overlaps($block, $b))
            // Not yet committed to `schedules` — still just sitting in
            // the in-memory preview batch, so it (and therefore this
            // conflict) can still be fixed by editing either side
            // before Save Schedule ever runs.
            ->map(fn ($b) => array_merge($b, ['is_saved' => false]));

        $saved = Schedule::forTerm($term->id)
            ->where('day', $block['day'])
            ->where('subject_offering_id', '!=', $block['subject_offering_id'])
            ->get()
            ->filter(fn (Schedule $s) => $this->overlaps($block, [
                'start_minutes' => $s->start_minutes,
                'end_minutes' => $s->end_minutes,
            ]))
            ->map(fn (Schedule $s) => [
                'subject_offering_id' => $s->subject_offering_id,
                'faculty_id' => $s->faculty_id,
                'faculty_name' => $s->faculty?->full_name,
                'room_id' => $s->room_id,
                'room_code' => $s->room?->room_code,
                'section_id' => $s->subjectOffering?->section_id,
                'section_code' => $s->subjectOffering?->section?->section_code,
                'subject_code' => $s->subjectOffering?->subject?->subject_code,
                'day' => $s->day,
                'start_minutes' => $s->start_minutes,
                'end_minutes' => $s->end_minutes,
                // Already committed and live on the Master Grid — a
                // conflict against this can't be waved through as
                // "fix it later," since there's nothing left to edit
                // on this side without a separate Remove Schedule/Edit
                // Schedule action against the real, saved row.
                'is_saved' => true,
            ]);

        return $preview->merge($saved)->values();
    }

    private function overlaps(array $a, array $b): bool
    {
        return ($a['start_minutes'] ?? 0) < ($b['end_minutes'] ?? 0)
            && ($a['end_minutes'] ?? 0) > ($b['start_minutes'] ?? 0);
    }

    private function facultyName(array $block): string
    {
        return $block['faculty_name'] ?? 'This faculty member';
    }

    private function conflict(string $type, array $current, ?array $conflicting, string $reason): array
    {
        return [
            'type' => $type,
            'reason' => $reason,
            'current' => $this->summarize($current),
            'conflicting' => $conflicting ? $this->summarize($conflicting) : null,
        ];
    }

    private function summarize(array $block): array
    {
        return [
            'subject_offering_id' => $block['subject_offering_id'] ?? null,
            'subject_code' => $block['subject_code'] ?? null,
            'section_code' => $block['section_code'] ?? null,
            'faculty_name' => $block['faculty_name'] ?? null,
            'room_code' => $block['room_code'] ?? null,
            'day' => $block['day'] ?? null,
            'start_minutes' => $block['start_minutes'] ?? null,
            'end_minutes' => $block['end_minutes'] ?? null,
            // Whether this block is already committed to the
            // `schedules` table (true) vs still just sitting in the
            // in-memory preview batch (false) — see overlappingOthers().
            // Absent entirely for $current (the block being edited
            // itself was never summarized with this key), which is
            // fine: only `conflicting` is ever checked for it.
            'is_saved' => $block['is_saved'] ?? false,
        ];
    }
}
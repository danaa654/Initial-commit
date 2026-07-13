<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Room;
use App\Models\SubjectOffering;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "how many hours can a Room host per week"
 * and "does this set of Subject Offerings exceed that." Both
 * RoomController::index()/manageSubjects() (the number Rooms/Index and
 * the Manage Subjects modal display) and
 * RoomController::syncPreferredSubjects() (the guard that actually
 * blocks over-scheduling) go through this class, so the displayed
 * ceiling and the enforced ceiling can never quietly drift apart.
 *
 * Weekly capacity is now DERIVED from the Academic Term's School Hours,
 * Lunch Break, and Working Days (see AcademicTerm::getWeeklyCapacityHoursAttribute())
 * rather than the old flat Room::WEEKLY_CAPACITY_HOURS constant. That
 * constant is kept only as a fallback for the rare case a term can't
 * produce a usable number (no Active/Planning term yet, or one missing
 * School Hours) — see weeklyCapacityHoursFor() below.
 */
class RoomCapacityService
{
    /**
     * The weekly capacity (in hours) that Room preferences/schedules
     * should be measured against for the given term. Falls back to the
     * legacy flat constant if the term can't produce a real number
     * (e.g. School Hours were never filled in) or if there is no term
     * at all, so callers never have to null-check this themselves.
     */
    public function weeklyCapacityHoursFor(?AcademicTerm $term): float
    {
        if ($term && $term->weekly_capacity_hours > 0) {
            return $term->weekly_capacity_hours;
        }

        return (float) Room::WEEKLY_CAPACITY_HOURS;
    }

    /**
     * Total Preferred Hours a set of Subject Offering ids would add up
     * to for this Room, for the given term. Used both to compute the
     * "would this exceed capacity" check below and to report the
     * current total back to the frontend.
     */
    public function totalHoursFor(Collection $offeringIds): int
    {
        if ($offeringIds->isEmpty()) {
            return 0;
        }

        return (int) SubjectOffering::whereIn('id', $offeringIds)->sum('hours');
    }

    /**
     * Whether attaching $offeringIds to $room would push its Preferred
     * Hours total for $term past that term's weekly capacity, plus a
     * ready-to-display message if so. Returned as a plain array (rather
     * than thrown) so the controller can abort_if() with the exact
     * message — matching this controller's existing abort_unless()
     * pattern and Laravel's abort()->json() shape of
     * {"message": "..."}, instead of ValidationException's generic
     * "The given data was invalid." wrapper.
     *
     * Deliberately compares against the FULL incoming selection, not a
     * diff against what was previously preferred — syncPreferredSubjects()
     * always replaces a room's entire preference set for the term, so
     * the incoming list IS the room's next total.
     */
    public function checkCapacity(Room $room, AcademicTerm $term, Collection $offeringIds): array
    {
        $capacity = $this->weeklyCapacityHoursFor($term);
        $total = $this->totalHoursFor($offeringIds);
        $exceeds = $total > $capacity;

        return [
            'exceeds' => $exceeds,
            'total' => $total,
            'capacity' => $capacity,
            'message' => $exceeds
                ? "This selection totals {$total} hour(s), which exceeds {$room->room_code}'s weekly capacity of {$capacity} hour(s) for {$term->display_name}. Remove some subjects or choose a different room."
                : null,
        ];
    }
}
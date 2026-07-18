<script setup>
import { computed, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import axios from 'axios'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import MasterGridHeader from './Partials/MasterGridHeader.vue'
import SubjectSidebar from './Partials/SubjectSidebar.vue'
import RoomSidebar from './Partials/RoomSidebar.vue'
import Timetable from './Partials/Timetable.vue'
import GenerateScheduleModal from './Partials/GenerateScheduleModal.vue'
import SessionSettingsModal from './Partials/SessionSettingsModal.vue'
import GeneratePreviewModal from './Partials/GeneratePreviewModal.vue'
import EditScheduleModal from './Partials/EditScheduleModal.vue'
import { useTimetableGrid } from '@/Composables/useTimetableGrid'
import { useFlashToast } from '@/Composables/useFlashToast'

defineOptions({
    layout: DashboardLayout,
})

const props = defineProps({
    activeTerm: { type: Object, default: null },
    subjectOfferings: { type: Array, default: () => [] },
    scheduledOfferings: { type: Array, default: () => [] },
    rooms: { type: Array, default: () => [] },
    departments: { type: Array, default: () => [] },
    programs: { type: Array, default: () => [] },
    specializations: { type: Array, default: () => [] },
    faculties: { type: Array, default: () => [] },
    savedSchedules: { type: Array, default: () => [] },
    collegeColors: { type: Object, default: () => ({}) },
    // Admin/Registrar only — see MasterGridController::index(). Dean/
    // Assistant Dean/OIC get { manage: false }: they can still view
    // the grid and open a block's details, but Generate Schedule is
    // hidden and the Edit modal opens read-only (see canManage below).
    can: { type: Object, default: () => ({ manage: false }) },
})

const canManage = computed(() => !!props.can?.manage)

// Same composable EditScheduleModal itself uses for its Day/Start
// options — reused here only for handleSubjectCardClick's defaults
// below, so "click to schedule" starts from a real, valid slot instead
// of a guessed one.
const { workingDays, timeRows } = useTimetableGrid(computed(() => props.activeTerm))

/* ── Sidebar collapse state ─────────────────────────────────────── */
const subjectsCollapsed = ref(false)
const roomsCollapsed = ref(false)

/* ── Room View selection ────────────────────────────────────────────
   Clicking a Room card switches the timetable into "Room View" —
   only schedules assigned to that room are shown. */
const selectedRoom = ref(null)

function selectRoom(room) {
    selectedRoom.value = selectedRoom.value?.id === room.id ? null : room
}

function clearSelectedRoom() {
    selectedRoom.value = null
}

/* ── Drag-and-drop conflict prevention ──────────────────────────────
   Tracks whichever Subject Offering is currently being dragged from
   SubjectSidebar (dragstart -> dragend/drop), purely so Timetable can
   compare its required room_type against selectedRoom's room_type
   WHILE the drag is still in progress — not only after the drop.
   dataTransfer's own payload can't be read during dragover (browser
   security restriction), so this sits in a plain ref instead and is
   handed down as a prop.

   This is a genuine prevention, not just a nicer error message: when
   the types don't match, Timetable's onDragOver never calls
   preventDefault(), which makes the browser itself refuse the drop
   (cursor shows "not-allowed") — the drop handler never even fires,
   so no request is made and no modal opens. The server-side rule in
   ScheduleValidationService (TYPE_ROOM_TYPE) still exists underneath
   this as the real safety net — this only stops the obviously-wrong
   case before the round trip. */
const draggedOffering = ref(null)

const { show: showToast } = useFlashToast()

/**
 * A drop that Timetable rejected outright (room-type mismatch — see
 * Timetable.vue's roomTypeMismatch/onDrop). This is the defense-in-depth
 * path only; onDragOver already stops the drop from ever firing in any
 * standards-compliant browser, so in practice this mostly exists so a
 * rejection is never silent if it ever does slip through.
 */
function handleDropRejected({ reason }) {
    showToast(reason, 'error')
}

/* ── Generate Schedule modal ──────────────────────────────────────── */
const showGenerateModal = ref(false)
const generating = ref(false)
const generateError = ref(null)

/* ── Session Settings modal (Generate Schedule, Step 2) ─────────────
   Target Selection (Step 1) hands off here before the Greedy
   Scheduler ever runs — see handleSessionSettings()/handleGenerate()
   below. sessionSettingsFilters keeps Step 1's picks around so
   "Back" can re-open GenerateScheduleModal without losing them, and
   so Regenerate (from the Step 4 preview) can re-open this same step
   for the same section. */
const showSessionSettingsModal = ref(false)
const sessionSettingsLoading = ref(false)
const sessionSettingsSaving = ref(false)
const sessionSettingsError = ref(null)
const sessionSettingsData = ref(null)
const sessionSettingsFilters = ref(null)

async function handleSessionSettings(filters) {
    sessionSettingsFilters.value = filters
    sessionSettingsError.value = null
    sessionSettingsData.value = null
    sessionSettingsLoading.value = true
    showGenerateModal.value = false
    showSessionSettingsModal.value = true

    try {
        const { data } = await axios.get(route('master-grid.session-settings'), { params: filters })
        sessionSettingsData.value = data
    } catch (err) {
        sessionSettingsError.value = err.response?.data?.message ?? 'Failed to load session settings. Please try again.'
    } finally {
        sessionSettingsLoading.value = false
    }
}

function backToTargetSelection() {
    showSessionSettingsModal.value = false
    showGenerateModal.value = true
}

/* ── Scheduled events (live Master Grid state) ──────────────────────
   Populated in-memory, but always kept in sync with what's actually
   committed in `schedules` — see applyEdit()/applyGeneratedPreview()
   below, both of which persist immediately rather than sitting in an
   "unsaved" local-only state. Seeded from already-committed Schedule
   rows so a returning Registrar sees the real, current schedule. */
const scheduledEvents = ref(
    props.savedSchedules.map((s) => ({ ...s, status: 'saved' }))
)
const lastGenerateSummary = ref(null)
const hasPreview = computed(() => scheduledEvents.value.length > 0)

/* ── Generate Schedule preview (review-first, save-on-commit) ───────
   Generate never writes anything anywhere. The raw response sits in
   generatePreview purely for review until the user explicitly clicks
   Save Changes — see applyGeneratedPreview() below, which posts
   straight to master-grid.save. Discarding never touches anything,
   since nothing was ever written to produce the preview. */
const showPreviewModal = ref(false)
const generatePreview = ref(null)
const generatePreviewSectionId = ref(null)
const applyingPreview = ref(false)
const applyError = ref(null)
// subject_offering_id => array of conflict objects (see
// ScheduleValidationService::conflict() for shape: type, reason,
// current, conflicting) — populated only when applyGeneratedPreview()
// fails with a 422. Lets the Schedule Preview modal show EXACTLY
// which row(s) conflicted and WHY, instead of just the generic banner
// ("One or more schedule blocks have conflicts. Nothing was saved.")
// that gave no way to tell which of the 8 rows was actually the
// problem.
const applyConflicts = ref(null)
// True for the ~3 seconds right after Save Changes has actually
// succeeded — see applyGeneratedPreview()/onSavedCelebrationDone().
// Drives ScheduleSuccessOverlay (confetti + centered "Saved" card)
// inside GeneratePreviewModal; the modal itself doesn't close until
// that celebration finishes.
const showPreviewSuccess = ref(false)

/* ── Subject Sidebar live preview overlay ────────────────────────────
   subjectOfferings (from the server) only ever reflects faculty_assigned
   from the `teaching_assignments` table — i.e. whatever was actually
   Saved. That's correct once persisted, but it means the Subject Card
   still says "Faculty: —" the moment a block is placed on the grid via
   an in-progress Generate Preview or an in-progress inline Edit, even
   though the Timetable itself already shows a faculty name for that
   same offering.

   This computed overlays THREE still-unsaved sources onto the
   server-provided offering list, purely for display — it never
   mutates subjectOfferings itself and never substitutes for the real
   Save step:

     1. generatePreview.value.blocks with status 'preview' — a batch
        just generated, sitting in the Review modal, not yet applied.
     2. scheduledEvents.value entries with status 'preview' — set only
        for rows freshly merged in from a Generate Preview batch that
        hasn't round-tripped through the server yet (see
        applyGeneratedPreview() below).
     3. draftGroup.value — the meeting-day block(s) currently open in
        the Edit Schedule modal. Opening/editing that modal never flips
        anything to status 'preview' (that flag only ever applies to a
        fresh Generate batch, never to editing an already-placed
        block), so without this source the sidebar would keep showing
        whatever was last saved even while the modal has a different
        faculty selected right in front of the user.

   Whichever source names a faculty for a given subject_offering_id
   wins over the server's last-saved value; if neither source touches
   an offering, its server-provided faculty_assigned passes through
   unchanged. */
const previewFacultyByOffering = computed(() => {
    const map = {}

    for (const block of generatePreview.value?.blocks ?? []) {
        if (block.status === 'preview' && block.faculty_name) {
            map[block.subject_offering_id] = block.faculty_name
        }
    }

    for (const event of scheduledEvents.value) {
        if (event.status === 'preview') {
            map[event.subject_offering_id] = event.faculty_name ?? null
        }
    }

    for (const block of draftGroup.value) {
        map[block.subject_offering_id] = block.faculty_name ?? null
    }

    return map
})

const sidebarOfferings = computed(() =>
    props.subjectOfferings.map((offering) => {
        const previewFaculty = previewFacultyByOffering.value[offering.id]

        return previewFaculty === undefined
            ? offering
            : { ...offering, faculty_assigned: previewFaculty }
    })
)

async function handleGenerate({ section_id, subjects }) {
    generating.value = true
    sessionSettingsSaving.value = true
    sessionSettingsError.value = null

    try {
        await axios.put(route('master-grid.session-settings.update'), { subjects })

        const { data } = await axios.post(route('master-grid.generate'), sessionSettingsFilters.value)

        generatePreview.value = data
        generatePreviewSectionId.value = section_id
        applyError.value = null
        applyConflicts.value = null
        showSessionSettingsModal.value = false
        showPreviewModal.value = true
    } catch (err) {
        sessionSettingsError.value = err.response?.data?.message ?? 'Failed to generate schedule. Please try again.'
    } finally {
        generating.value = false
        sessionSettingsSaving.value = false
    }
}

/**
 * "Back" from the Schedule Preview (Step 4) — throws the current
 * preview away (nothing in it was ever saved, so there's nothing to
 * lose) and reopens Session Settings for the SAME section, instead of
 * closing the whole flow the way Discard does. sessionSettingsData
 * and sessionSettingsFilters are both still exactly what they were
 * when handleGenerate() last ran — neither is cleared anywhere on the
 * way to the preview — so SessionSettingsModal reopens pre-filled and
 * ready to adjust, rather than needing to re-fetch anything or walk
 * back through Target Selection first.
 */
function backToSessionSettings() {
    showPreviewModal.value = false
    generatePreview.value = null
    generatePreviewSectionId.value = null
    applyError.value = null
    applyConflicts.value = null
    showSessionSettingsModal.value = true
}

/**
 * The user reviewed the preview and clicked "Save Changes" — this now
 * commits straight to the database. Reviewing the table IS the
 * checking step; there is no separate "applied but unsaved" state
 * anymore. Additive per section, same rule as before: only the blocks
 * belonging to the section just generated are replaced, everything
 * else (other sections' already-saved schedules) is untouched.
 */
async function applyGeneratedPreview() {
    if (!generatePreview.value) return

    applyingPreview.value = true
    applyError.value = null
    applyConflicts.value = null

    const data = generatePreview.value
    const newBlocks = data.blocks.filter((block) => block.status === 'preview')

    const mergedBlocks = scheduledEvents.value
        .filter((event) => event.section_id !== generatePreviewSectionId.value)
        .concat(newBlocks)

    try {
        await axios.post(route('master-grid.save'), {
            blocks: mergedBlocks,
        })

        lastGenerateSummary.value = {
            scheduled: data.scheduled_count,
            unscheduled: data.unscheduled_count,
            unplaced: data.blocks.filter((block) => block.status !== 'preview'),
        }

        // Reflect the merge immediately so the grid doesn't sit blank
        // while the background reload below catches up.
        scheduledEvents.value = mergedBlocks.map((event) => ({ ...event, status: 'saved' }))

        // Deliberately NOT closing the modal here. Save Schedule is a
        // real, final commit to the database — it earns a moment of
        // "yes, that worked" (confetti + centered success card, see
        // ScheduleSuccessOverlay) rather than the modal just vanishing.
        // showPreviewSuccess drives that overlay; the modal itself is
        // only actually closed once ScheduleSuccessOverlay's own timer
        // finishes and emits 'saved-celebration-done' — see
        // onSavedCelebrationDone() below.
        showPreviewSuccess.value = true

        // Resync from the database once the reload actually lands —
        // this is the real source of truth from here on. Runs
        // immediately (not deferred to celebration-done) so the data
        // is already fresh by the time the modal closes.
        router.reload({
            only: ['subjectOfferings', 'scheduledOfferings', 'rooms', 'savedSchedules'],
            onSuccess: (page) => {
                scheduledEvents.value = page.props.savedSchedules.map((s) => ({ ...s, status: 'saved' }))
            },
        })
    } catch (err) {
        if (err.response?.status === 422 && err.response.data?.conflicts) {
            conflictingIds.value = Object.keys(err.response.data.conflicts).map(Number)
            applyConflicts.value = err.response.data.conflicts
            applyError.value = err.response.data.message
        } else {
            applyError.value = 'Failed to save the schedule. Please try again.'
        }
    } finally {
        applyingPreview.value = false
    }
}

/**
 * Fires once ScheduleSuccessOverlay's own 3-second timer finishes.
 * Only now does the Schedule Preview modal actually close and reset —
 * everything the celebration overlay is covering stays exactly as-is
 * underneath it in the meantime.
 */
function onSavedCelebrationDone() {
    showPreviewSuccess.value = false
    showPreviewModal.value = false
    generatePreview.value = null
    generatePreviewSectionId.value = null
}

/**
 * Throws the whole preview away — nothing was ever written anywhere,
 * so there's nothing to undo.
 */
function discardGeneratedPreview() {
    showPreviewModal.value = false
    generatePreview.value = null
    generatePreviewSectionId.value = null
    applyError.value = null
    applyConflicts.value = null
}

/* ── Phase 2: Interactive Schedule Review ─────────────────────────── */

const showEditModal = ref(false)
// The ORIGINAL meeting-day blocks for the subject being edited — one
// entry per meeting (e.g. 2 entries for a 2x/week subject: Monday's
// row and Wednesday's row). Untouched; used both for Cancel and to
// know which real blocks to patch on Apply. A single-block click on
// the Master Grid still ends up here as a group of however many
// sibling meeting-days that subject actually has — see openEditModal.
const editingGroup = ref([])
// Same blocks, index-aligned, with the latest edited fields merged in
// — this is what's shown live everywhere while the modal is open.
const draftGroup = ref([])
const validating = ref(false)
const currentConflicts = ref([])
// Same conflicts as currentConflicts, but kept per meeting-day index
// (aligned with editingGroup/draftGroup) instead of flattened — this
// is what lets a preview-context Apply tag exactly which meeting
// day(s) are the problem, rather than marking the whole group.
const currentConflictsByIndex = ref([])
const currentWarnings = ref([])

// Representative block for the read-only info panel (Subject/Program/
// Year-Section/Units/Term) and for eligibility rules — identical
// across every meeting-day of the same subject by construction, so any
// one of them works.
const editingBlock = computed(() => editingGroup.value[0] ?? null)

// 'grid'    — editing already-saved block(s) straight on the Master Grid.
// 'preview' — editing row(s) inside the (not-yet-saved) Schedule Preview
//             modal. Same modal, same validation call, just a different
//             sibling list to check against and a different place the
//             accepted edit gets written back to.
const editContext = ref('grid')

// True only while editing a "fresh placement" — a subject just
// dragged onto the grid (handleDropSubject below), or a Failed row
// being manually resolved from Schedule Preview. Drives both
// EditScheduleModal's Hours/Meetings fields AND whether applyEdit()
// below persists a change to those two fields via the same
// session-settings endpoint Generate Schedule's own Step 2 already
// uses — see allowSessionSettings' docblock on EditScheduleModal for
// why an ordinary already-saved block never touches either.
const allowSessionSettings = ref(false)

const showConflictModal = ref(false)
const conflictRecommendations = ref(null)

const saving = ref(false)
const saveError = ref(null)

// subject_offering_ids currently failing validation — populated right
// before/after a failed save attempt (bulk apply or Save Schedule), so
// blocks can be highlighted on the grid per spec ("Highlight all
// conflicting schedule blocks").
const conflictingIds = ref([])

/**
 * Opens Edit Schedule for a subject. Accepts either:
 *   - an array of blocks (GeneratePreviewModal already groups a
 *     subject's meeting days together before emitting), or
 *   - a single block (a direct click on one day's block in the
 *     Timetable grid) — in which case every OTHER meeting-day sharing
 *     the same subject_offering_id is looked up and pulled in too, so
 *     a 2x/3x subject is always edited as one connected group, never
 *     as an isolated single day.
 */
function openEditModal(input, context = 'grid', { allowSessionSettings: allowSettings = false } = {}) {
    editContext.value = context
    allowSessionSettings.value = allowSettings

    let group

    if (Array.isArray(input)) {
        group = input
    } else {
        const siblingSource = context === 'preview'
            ? (generatePreview.value?.blocks ?? []).filter((b) => b.status === 'preview')
            : scheduledEvents.value

        group = siblingSource.filter((b) => b.subject_offering_id === input.subject_offering_id)
        if (!group.length) group = [input]
    }

    editingGroup.value = group
    draftGroup.value = group.map((b) => ({ ...b }))
    currentConflicts.value = []
    currentConflictsByIndex.value = []
    currentWarnings.value = []
    showEditModal.value = true

    // If this row already came in flagged conflicting — either a
    // live edit-time conflict recorded on a Preview row (see
    // GeneratePreviewModal's liveConflictsFor()), or one surfaced by
    // Save Changes itself coming back 422 (saveConflictsFor()) — run
    // the exact same check the person would otherwise only see AFTER
    // nudging a field, right away. Without this, clicking a
    // conflicted row opened Edit Schedule looking perfectly clean:
    // no conflict list, no Suggestions panel, until the person
    // second-guessed which field to touch first.
    const alreadyFlagged = group.some((b) => (b.conflicts && b.conflicts.length) || (b.subject_offering_id && applyConflicts.value?.[b.subject_offering_id]?.length))

    if (alreadyFlagged && canManage.value) {
        validateDraft({
            faculty_id: group[0]?.faculty_id ?? null,
            room_id: group[0]?.room_id ?? null,
            days: group.map((b) => b.day),
            start_minutes: group[0]?.start_minutes ?? null,
            end_minutes: group[0]?.end_minutes ?? null,
        })
    }
}

/**
 * A subject was dragged from SubjectSidebar and dropped on an empty
 * Timetable cell (see Timetable.vue's onDrop). Builds a synthetic
 * "fresh placement" block — Room/Day/Start already known from where
 * it was dropped, Faculty pre-filled only if the offering already has
 * a Faculty Loading assignment, Hours/Meetings seeded from the
 * offering's current values — then opens it through the exact same
 * Edit Schedule modal used everywhere else, in 'grid' context so
 * Apply Changes writes straight to `schedules`.
 *
 * Unlike every other openEditModal() call, this one also runs
 * validateDraft() immediately even though nothing is "already
 * flagged" — Room/Day/Start are real values the instant it's
 * dropped, not blank fields waiting on the person to touch something,
 * so a genuine conflict (e.g. this section already has a class at
 * this exact time, in a different room) needs to surface right away
 * rather than waiting for the first field the person happens to
 * change.
 */
function handleDropSubject({ subjectOfferingId, roomId, day, startMinutes }) {
    if (!canManage.value) return

    const offering = props.subjectOfferings.find((o) => o.id === subjectOfferingId)
    if (!offering) return

    const room = props.rooms.find((r) => r.id === roomId)
    const meetings = offering.meetings_per_week || 1
    const durationMinutes = offering.hours ? Math.round((offering.hours / meetings) * 60) : null

    const block = {
        subject_offering_id: offering.id,
        academic_term_id: offering.academic_term_id,
        subject_code: offering.subject_code,
        descriptive_title: offering.descriptive_title,
        program_code: offering.program_code,
        department_id: offering.department_id,
        year_level: offering.year_level,
        section_id: offering.section_id,
        section_code: offering.section_code,
        units: offering.units,
        hours: offering.hours,
        meetings_per_week: meetings,
        classification: offering.classification,
        room_type: offering.room_type,
        faculty_id: offering.faculty_id ?? null,
        faculty_name: offering.faculty_assigned ?? null,
        room_id: roomId,
        room_code: room?.room_code ?? null,
        day,
        start_minutes: startMinutes,
        end_minutes: durationMinutes !== null ? startMinutes + durationMinutes : null,
    }

    openEditModal([block], 'grid', { allowSessionSettings: true })

    showToast(
        `${offering.subject_code} placed on ${room?.room_code ?? 'the grid'} — review and Apply Changes to save.`,
        'success'
    )
}

/**
 * Click-to-schedule — SubjectSidebar's alternative to dragging a card
 * onto the grid. Exists for exactly the case drag-and-drop is awkward
 * for: the room that's actually needed (say, a CCS lab for an SHTM
 * section's ITE class) isn't one Room View happens to be showing, or
 * dragging onto it would just get rejected outright since it isn't
 * Allowed for this program — Room Eligibility Override on the Edit
 * Schedule modal is how that gets placed anyway, and Override only
 * exists inside that modal, not as a drop-time option. So instead of
 * requiring a drag onto some specific valid cell first, this opens the
 * same modal with a real (but unconfirmed) Day/Start default and Room
 * left unset — Apply Changes only enables once every required field,
 * including Room, is actually filled in.
 *
 * Day/Start default to the grid's own first working day and first
 * non-lunch slot (not just placeholder zeros) so the instant-conflict
 * check below has something real to check — same reasoning
 * handleDropSubject's docblock gives for validating immediately rather
 * than waiting for the first field the person touches.
 */
function handleSubjectCardClick(offering) {
    if (!canManage.value) return

    const defaultDay = workingDays.value[0]?.field ?? null
    const defaultStart = timeRows.value.find((row) => row.type === 'slot')?.startMinutes ?? null

    const meetings = offering.meetings_per_week || 1
    const durationMinutes = offering.hours ? Math.round((offering.hours / meetings) * 60) : null
    const endMinutes = durationMinutes !== null && defaultStart !== null ? defaultStart + durationMinutes : null

    // Pre-fill Room from the offering's Preferred Room (set on Subject
    // Offerings) so the modal doesn't open with Room blank when a
    // preference already exists — same lookup-by-room_code pattern
    // openEditModal/applyEdit use elsewhere in this file.
    const preferredRoom = offering.preferred_room_code
        ? props.rooms.find((r) => r.room_code === offering.preferred_room_code)
        : null

    const block = {
        subject_offering_id: offering.id,
        academic_term_id: offering.academic_term_id,
        subject_code: offering.subject_code,
        descriptive_title: offering.descriptive_title,
        program_code: offering.program_code,
        department_id: offering.department_id,
        year_level: offering.year_level,
        section_id: offering.section_id,
        section_code: offering.section_code,
        units: offering.units,
        hours: offering.hours,
        meetings_per_week: meetings,
        classification: offering.classification,
        room_type: offering.room_type,
        faculty_id: offering.faculty_id ?? null,
        faculty_name: offering.faculty_assigned ?? null,
        room_id: preferredRoom?.id ?? null,
        room_code: preferredRoom?.room_code ?? null,
        day: defaultDay,
        start_minutes: defaultStart,
        end_minutes: endMinutes,
    }

    openEditModal([block], 'grid', { allowSessionSettings: true })

    if (defaultDay && defaultStart !== null) {
        validateDraft({
            faculty_id: block.faculty_id,
            room_id: block.room_id,
            days: [block.day],
            start_minutes: block.start_minutes,
            end_minutes: block.end_minutes,
        })
    }

    showToast(
        `${offering.subject_code} opened for scheduling — set Room (and Faculty, if needed), then Apply Changes to save.`,
        'success'
    )
}

function closeEditModal() {
    showEditModal.value = false
    editingGroup.value = []
    draftGroup.value = []
    currentConflicts.value = []
    currentConflictsByIndex.value = []
    currentWarnings.value = []
    showConflictModal.value = false
    allowSessionSettings.value = false
}

let validateToken = 0

/**
 * fields: { faculty_id, room_id, days: [...], start_minutes, end_minutes }
 * — days is index-aligned with editingGroup/draftGroup (one entry per
 * meeting-day instance), coming straight from EditScheduleModal.
 */
async function validateDraft(fields) {
    if (!editingGroup.value.length) return
    // Defense in depth: the backend already rejects validate-block for
    // anyone but Admin/Registrar (see MasterGridController::
    // middleware()), and EditScheduleModal's inputs are disabled for
    // everyone else so this should never actually fire — but bail out
    // here too rather than relying solely on the modal never emitting
    // field-changed.
    if (!canManage.value) return

    const faculty = props.faculties.find((f) => f.id === fields.faculty_id)
    const facultyName = faculty
        ? [faculty.first_name, faculty.last_name].filter(Boolean).join(' ')
        : null

    const days = fields.days ?? editingGroup.value.map((b) => b.day)

    // Every meeting-day instance, patched with the same faculty/room/
    // time and its own (possibly changed) day. Iterates over `days`
    // itself rather than editingGroup — normally the same length, but
    // allowSessionSettings lets meetings_per_week grow on a fresh
    // placement (see EditScheduleModal's onMeetingsChange), which
    // means `days` can now have MORE entries than the original group
    // had. Any index past the original group's length falls back to
    // its first member as a template for the shared (non-day) fields.
    draftGroup.value = days.map((day, i) => ({
        ...(editingGroup.value[i] ?? editingGroup.value[0] ?? {}),
        faculty_id: fields.faculty_id,
        faculty_name: facultyName,
        room_id: fields.room_id,
        room_override: fields.room_override,
        day: day ?? editingGroup.value[i]?.day,
        start_minutes: fields.start_minutes,
        end_minutes: fields.end_minutes,
        hours: fields.hours ?? editingGroup.value[i]?.hours,
        meetings_per_week: fields.meetings_per_week ?? editingGroup.value[i]?.meetings_per_week,
    }))

    const token = ++validateToken
    validating.value = true

    // Every OTHER block, with each group member swapped for its latest
    // draft — matched by its ORIGINAL subject_offering_id + day (the
    // group's snapshot at open time), since the day itself may have
    // just changed in the draft.
    const siblingSource = editContext.value === 'preview'
        ? (generatePreview.value?.blocks ?? []).filter((b) => b.status === 'preview')
        : scheduledEvents.value

    const allBlocks = siblingSource.map((event) => {
        const idx = editingGroup.value.findIndex(
            (b) => b.subject_offering_id === event.subject_offering_id && b.day === event.day
        )
        return idx !== -1 ? draftGroup.value[idx] : event
    })

    try {
        // Validate each meeting-day instance separately — Monday's
        // faculty/room/time can conflict independently of Wednesday's,
        // even though this UI edits every meeting day together.
        const conflictsByIndex = []
        let warnings = []
        let recommendations = null

        for (const draft of draftGroup.value) {
            const { data } = await axios.post(route('master-grid.validate-block'), {
                block: draft,
                blocks: allBlocks,
            })

            conflictsByIndex.push(data.conflicts)
            warnings = warnings.concat(data.warnings)
            if (data.conflicts.length && !recommendations) recommendations = data.recommendations
        }

        if (token !== validateToken) return // a newer edit superseded this check

        currentConflictsByIndex.value = conflictsByIndex
        currentConflicts.value = conflictsByIndex.flat()
        currentWarnings.value = warnings

        if (currentConflicts.value.length > 0) {
            conflictRecommendations.value = recommendations
            showConflictModal.value = true
        } else {
            showConflictModal.value = false
        }
    } catch (err) {
        // Surface whatever the server actually said whenever it said
        // anything — a 422 validation failure has err.response.data.
        // message, and (outside production, where APP_DEBUG exposes
        // it) so does an unhandled 500. Falling straight to the
        // generic string every time made a real, fixable server error
        // indistinguishable from an ordinary network hiccup — this is
        // what let the section_id bug go unnoticed as anything more
        // specific than "Could not check for conflicts." Always logs
        // the raw error too, so it's at least visible in DevTools even
        // when nothing user-facing can be shown for it.
        console.error('validate-block failed:', err)

        const serverMessage = err?.response?.data?.message
        currentConflicts.value = [{
            type: 'error',
            reason: serverMessage
                ? `Could not check for conflicts — ${serverMessage}`
                : 'Could not check for conflicts. Please try again.',
        }]
        currentConflictsByIndex.value = draftGroup.value.map(() => currentConflicts.value)
    } finally {
        if (token === validateToken) validating.value = false
    }
}

function dismissConflictModal() {
    showConflictModal.value = false
}

function applySuggestedFaculty(facultyId) {
    validateDraft({
        faculty_id: facultyId,
        room_id: draftGroup.value[0]?.room_id ?? null,
        start_minutes: draftGroup.value[0]?.start_minutes ?? null,
        end_minutes: draftGroup.value[0]?.end_minutes ?? null,
        days: draftGroup.value.map((b) => b.day),
    })
}

function applySuggestedRoom(roomId) {
    validateDraft({
        faculty_id: draftGroup.value[0]?.faculty_id ?? null,
        room_id: roomId,
        start_minutes: draftGroup.value[0]?.start_minutes ?? null,
        end_minutes: draftGroup.value[0]?.end_minutes ?? null,
        days: draftGroup.value.map((b) => b.day),
    })
}

function applySuggestedTime({ days, day, start_minutes, end_minutes }) {
    // A suggested time now comes back as a full day-combo (see
    // ScheduleRecommendationService::suggestTimes) — `days` has
    // exactly as many entries as this subject's meetings_per_week
    // (e.g. ['wednesday', 'thursday'] for a 2x/week subject), already
    // in the same order draftGroup's meeting-day blocks are in. Apply
    // it index-for-index so EVERY meeting day moves to its matching
    // day in the combo, all sharing the same new time — not just the
    // first meeting day. `day` (singular) is kept as a fallback only
    // for safety if an older cached response ever lacks `days`.
    const comboDays = days && days.length ? days : [day]

    const newDays = draftGroup.value.map((b, i) => comboDays[i] ?? comboDays[comboDays.length - 1] ?? b.day)

    validateDraft({
        faculty_id: draftGroup.value[0]?.faculty_id ?? null,
        room_id: draftGroup.value[0]?.room_id ?? null,
        start_minutes,
        end_minutes,
        days: newDays,
    })
}

/**
 * Applies a "meet more often" suggestion (see
 * ScheduleRecommendationService::suggestMeetingSplits() and
 * EditScheduleModal's "Or Meet More Often" section). Unlike
 * applySuggestedFaculty/Room/Time, this changes meetings_per_week
 * itself — total weekly hours stay exactly what they already were
 * (splitting is redistributing the same hours across more/shorter
 * meetings, never adding or removing hours), only the day-combo,
 * time, and meeting count change.
 *
 * Only ever reachable when allowSessionSettings is true — the
 * suggestion list itself is hidden otherwise (see EditScheduleModal's
 * v-if on that section) — so applyEdit's existing "persist hours/
 * meetings_per_week via session-settings.update" path already covers
 * committing this when the person clicks Apply Changes; nothing extra
 * is needed here beyond feeding validateDraft the new fields.
 */
function applySuggestedMeetingSplit({ days, day, start_minutes, end_minutes, meetings_per_week }) {
    const comboDays = days && days.length ? days : [day]

    validateDraft({
        faculty_id: draftGroup.value[0]?.faculty_id ?? null,
        room_id: draftGroup.value[0]?.room_id ?? null,
        start_minutes,
        end_minutes,
        days: comboDays,
        meetings_per_week,
        hours: draftGroup.value[0]?.hours ?? editingGroup.value[0]?.hours ?? null,
    })
}

/**
 * Commits the current draft. A 'preview' edit patches the row(s)
 * inside the still-unsaved Schedule Preview result — nothing here is
 * in the database yet, so a conflict is recorded (each affected
 * meeting-day block gets its own `conflicts` array) rather than
 * blocked outright. That row shows red in the Preview table until
 * it's re-edited clean; only the Preview's own Save Changes actually
 * needs every row conflict-free, since THAT step is the real write.
 *
 * A 'grid' edit is a change to an ALREADY SAVED block, so it's still
 * hard-blocked on any unresolved conflict — Apply Changes there saves
 * straight to the database immediately, with no later "resolve before
 * saving" step to catch a bad edit.
 *
 * Every block in editingGroup gets patched — not just the one whose
 * day happened to be clicked — which is what keeps a 2x/3x subject's
 * faculty/room/time in sync across all of its meeting days instead of
 * letting one day silently drift onto a different faculty.
 */
async function applyEdit(fields) {
    const faculty = props.faculties.find((f) => f.id === fields.faculty_id)
    const facultyName = faculty ? [faculty.first_name, faculty.last_name].filter(Boolean).join(' ') : null
    const roomCode = props.rooms.find((r) => r.id === fields.room_id)?.room_code ?? null
    const days = fields.days ?? editingGroup.value.map((b) => b.day)

    // Iterates over `days` (not editingGroup) for the same reason
    // validateDraft's draftGroup construction does — allowSessionSettings
    // lets `days` grow past the original group's length on a fresh
    // placement (see onMeetingsChange). Any index beyond the original
    // group falls back to its first member as a template for the
    // shared (non-day) fields, since it's the same offering either way.
    const patchFor = (i) => ({
        ...(editingGroup.value[i] ?? editingGroup.value[0] ?? {}),
        faculty_id: fields.faculty_id,
        faculty_name: facultyName,
        room_id: fields.room_id,
        room_code: roomCode,
        room_override: fields.room_override,
        day: days[i],
        start_minutes: fields.start_minutes,
        end_minutes: fields.end_minutes,
        hours: fields.hours ?? editingGroup.value[i]?.hours ?? editingGroup.value[0]?.hours,
        meetings_per_week: fields.meetings_per_week ?? editingGroup.value[i]?.meetings_per_week ?? editingGroup.value[0]?.meetings_per_week,
    })

    // Hours/Meetings-per-week live on subject_offerings, not schedules
    // — only ever touched here for a fresh placement (allowSessionSettings),
    // reusing the exact same endpoint Generate Schedule's own Step 2
    // (Session Settings) persists through. Done BEFORE the schedule
    // itself is written so a failure here (e.g. a stale/invalid value)
    // never leaves the schedule and the offering's hours/meetings out
    // of sync with each other.
    if (allowSessionSettings.value && editingBlock.value?.subject_offering_id) {
        try {
            await axios.put(route('master-grid.session-settings.update'), {
                subjects: [{
                    subject_offering_id: editingBlock.value.subject_offering_id,
                    hours: fields.hours,
                    meetings_per_week: fields.meetings_per_week,
                }],
            })
        } catch (err) {
            saveError.value = err.response?.data?.message ?? 'Failed to update hours/meetings for this subject.'
            return
        }
    }

    if (editContext.value === 'preview') {
        // Patch every matching meeting-day row in place, same as
        // before — PLUS append any day beyond the original group's
        // length as a brand-new preview row (a Failed row being
        // resolved with meetings_per_week increased from 1x to 2x/3x
        // has no existing sibling rows for those extra days at all).
        const existingBlocks = generatePreview.value.blocks.map((block) => {
            const idx = editingGroup.value.findIndex(
                (b) => b.subject_offering_id === block.subject_offering_id && b.day === block.day
            )
            if (idx === -1) return block

            return {
                ...block,
                ...patchFor(idx),
                status: 'preview',
                conflicts: currentConflictsByIndex.value[idx] ?? [],
            }
        })

        const extraBlocks = days.slice(editingGroup.value.length).map((_, offset) => {
            const i = editingGroup.value.length + offset
            return {
                ...patchFor(i),
                status: 'preview',
                conflicts: currentConflictsByIndex.value[i] ?? [],
            }
        })

        generatePreview.value = {
            ...generatePreview.value,
            blocks: [...existingBlocks, ...extraBlocks],
        }

        closeEditModal()
        return
    }

    // 'grid' context — this writes straight to the database below, so
    // an unresolved conflict still blocks it outright.
    if (currentConflicts.value.length > 0) return

    const mergedBlocks = [...scheduledEvents.value]

    days.forEach((_, i) => {
        const template = editingGroup.value[i]
        const patch = patchFor(i)

        if (template) {
            const idx = mergedBlocks.findIndex(
                (event) => event.subject_offering_id === template.subject_offering_id && event.day === template.day
            )
            if (idx !== -1) {
                mergedBlocks[idx] = { ...mergedBlocks[idx], ...patch }
                return
            }
        }

        // No existing sibling to patch — either a brand-new placement
        // (drag-and-drop, or a Failed row resolved straight on the
        // grid) or an extra meeting day added past the original
        // group's length. Either way, this is a new committed row.
        mergedBlocks.push(patch)
    })

    saving.value = true
    saveError.value = null

    try {
        await axios.post(route('master-grid.save'), { blocks: mergedBlocks })

        scheduledEvents.value = mergedBlocks.map((event) => ({ ...event, status: 'saved' }))
        closeEditModal()

        router.reload({
            only: ['subjectOfferings', 'scheduledOfferings', 'rooms', 'savedSchedules'],
            onSuccess: (page) => {
                scheduledEvents.value = page.props.savedSchedules.map((s) => ({ ...s, status: 'saved' }))
            },
        })
    } catch (err) {
        if (err.response?.status === 422 && err.response.data?.conflicts) {
            conflictingIds.value = Object.keys(err.response.data.conflicts).map(Number)
            saveError.value = err.response.data.message
        } else {
            saveError.value = 'Failed to save this change. Please try again.'
        }
    } finally {
        saving.value = false
    }
}

/* ── Remove Schedule (already-committed grid block) ─────────────────
   Deletes every meeting-day row for one subject_offering_id from
   `schedules` — the Faculty Loading assignment is left untouched
   server-side (see MasterGridController::removeSchedule()). Mirrors
   applyEdit()'s grid-save pattern: optimistic local update, then a
   background reload of the props that actually derive from the
   database (subjectOfferings/scheduledOfferings/savedSchedules) so
   the Subject Sidebar's "unscheduled" count and the grid agree with
   what the server now has. */
const removingSchedule = ref(false)
const removeError = ref(null)

async function handleRemoveSchedule(subjectOfferingId) {
    if (!subjectOfferingId) return

    removingSchedule.value = true
    removeError.value = null

    try {
        await axios.delete(route('master-grid.remove-schedule'), {
            data: { subject_offering_id: subjectOfferingId },
        })

        scheduledEvents.value = scheduledEvents.value.filter(
            (event) => event.subject_offering_id !== subjectOfferingId
        )
        conflictingIds.value = conflictingIds.value.filter((id) => id !== subjectOfferingId)
        closeEditModal()

        router.reload({
            only: ['subjectOfferings', 'scheduledOfferings', 'rooms', 'savedSchedules'],
            onSuccess: (page) => {
                scheduledEvents.value = page.props.savedSchedules.map((s) => ({ ...s, status: 'saved' }))
            },
        })
    } catch (err) {
        removeError.value = err.response?.data?.message ?? 'Failed to remove this schedule. Please try again.'
    } finally {
        removingSchedule.value = false
    }
}

const hasActiveTerm = computed(() => !!props.activeTerm)
</script>

<template>

<Head title="Master Grid" />

<div class="flex flex-col h-full w-full min-w-0 overflow-hidden">

    <!-- Page header — same treatment as Subjects/Faculty/etc. -->
    <div class="mb-4 shrink-0">
        <h1 class="text-3xl font-bold text-[var(--text-primary)]">
            Master Grid
        </h1>
        <p class="text-[var(--text-muted)] mt-1">
            Scheduling workspace for the active Academic Term — Subjects, Rooms, and the Timetable grid.
        </p>
    </div>

    <!-- Preview summary banner -->
    <div
        v-if="lastGenerateSummary && lastGenerateSummary.unplaced.length > 0"
        class="mb-4 shrink-0 rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-700 px-4 py-2 text-xs text-amber-800 dark:text-amber-300"
    >
        <span class="font-bold">{{ lastGenerateSummary.scheduled }} scheduled</span>
        · {{ lastGenerateSummary.unplaced.length }} could not be placed:
        <span v-for="(item, i) in lastGenerateSummary.unplaced" :key="item.subject_offering_id">
            {{ item.subject_code }}{{ i < lastGenerateSummary.unplaced.length - 1 ? ',' : '' }}
        </span>
    </div>

    <!-- Save error banner -->
    <div
        v-if="saveError"
        class="mb-4 shrink-0 rounded-xl border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-700 px-4 py-2 text-xs text-red-800 dark:text-red-300"
    >
        {{ saveError }} — conflicting blocks are highlighted in red on the grid.
    </div>

    <!-- Remove Schedule error banner -->
    <div
        v-if="removeError"
        class="mb-4 shrink-0 rounded-xl border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-700 px-4 py-2 text-xs text-red-800 dark:text-red-300"
    >
        {{ removeError }}
    </div>

    <!-- Workspace card — sized to fit the viewport exactly, no page-level scroll -->
    <div class="master-grid-shell flex-1 flex flex-col min-h-0 min-w-0 w-full bg-[var(--card-bg)] border border-[var(--card-border)] rounded-2xl shadow overflow-hidden">

        <MasterGridHeader
            :active-term="activeTerm"
            :selected-room="selectedRoom"
            :saving="saving"
            :can-manage="canManage"
            @clear-room="clearSelectedRoom"
            @generate="showGenerateModal = true"
        />

        <div v-if="!hasActiveTerm" class="flex-1 flex items-center justify-center p-10">
            <div class="text-center max-w-md">
                <p class="text-4xl mb-3">🗓️</p>
                <p class="font-bold text-[var(--text-primary)]">No Active Academic Term</p>
                <p class="text-sm text-[var(--text-muted)] mt-1">
                    Activate an Academic Term to open the Master Grid workspace.
                </p>
            </div>
        </div>

        <div v-else class="flex-1 flex min-h-0 min-w-0 overflow-hidden">
            <!-- LEFT/CENTER: Timetable -->
            <div
                class="flex-1 min-w-0 overflow-auto custom-scrollbar-theme"
                style="background: var(--page-bg)"
            >
                <Timetable
                    :academic-term="activeTerm"
                    :selected-room="selectedRoom"
                    :rooms="rooms"
                    :scheduled-events="scheduledEvents"
                    :college-colors="collegeColors"
                    :editable="hasPreview"
                    :can-manage="canManage"
                    :conflicting-ids="conflictingIds"
                    :dragged-offering="draggedOffering"
                    @edit-block="openEditModal"
                    @select-room="selectRoom"
                    @drop-subject="handleDropSubject"
                    @drop-rejected="handleDropRejected"
                />
            </div>

            <!-- RIGHT: Subjects + Rooms, side by side with each other -->
            <div class="shrink-0 flex min-h-0 border-l border-[var(--card-border)]">
                <SubjectSidebar
                    v-model:collapsed="subjectsCollapsed"
                    :offerings="sidebarOfferings"
                    :scheduled-offerings="scheduledOfferings"
                    :college-colors="collegeColors"
                    :can-manage="canManage"
                    @drag-start="draggedOffering = $event"
                    @drag-end="draggedOffering = null"
                    @card-click="handleSubjectCardClick"
                />

                <RoomSidebar
                    v-model:collapsed="roomsCollapsed"
                    :rooms="rooms"
                    :selected-room="selectedRoom"
                    :college-colors="collegeColors"
                    @select="selectRoom"
                />
            </div>
        </div>
    </div>
</div>

<GenerateScheduleModal
    :show="showGenerateModal"
    :departments="departments"
    :programs="programs"
    :specializations="specializations"
    :subject-offerings="subjectOfferings"
    :generating="sessionSettingsLoading"
    :error="generateError"
    @close="showGenerateModal = false"
    @session-settings="handleSessionSettings"
/>

<SessionSettingsModal
    :show="showSessionSettingsModal"
    :loading="sessionSettingsLoading"
    :saving="generating || sessionSettingsSaving"
    :error="sessionSettingsError"
    :data="sessionSettingsData"
    @close="showSessionSettingsModal = false"
    @back="backToTargetSelection"
    @generate="handleGenerate"
/>

<GeneratePreviewModal
    :show="showPreviewModal"
    :result="generatePreview"
    :saving="applyingPreview"
    :error="applyError"
    :conflicts="applyConflicts"
    :just-saved="showPreviewSuccess"
    @save="applyGeneratedPreview"
    @discard="discardGeneratedPreview"
    @back="backToSessionSettings"
    @edit-block="(block) => openEditModal(block, 'preview', { allowSessionSettings: block[0]?.status !== 'preview' })"
    @saved-celebration-done="onSavedCelebrationDone"
/>

<EditScheduleModal
    :show="showEditModal"
    :block="editingBlock"
    :blocks="draftGroup"
    :context="editContext"
    :allow-session-settings="allowSessionSettings"
    :academic-term="activeTerm"
    :faculties="faculties"
    :departments="departments"
    :rooms="rooms"
    :conflicts="currentConflicts"
    :warnings="currentWarnings"
    :recommendations="conflictRecommendations"
    :validating="validating"
    :read-only="!canManage"
    :removing="removingSchedule"
    @close="closeEditModal"
    @field-changed="validateDraft"
    @apply="applyEdit"
    @apply-faculty="applySuggestedFaculty"
    @apply-room="applySuggestedRoom"
    @apply-time="applySuggestedTime"
    @apply-meeting-split="applySuggestedMeetingSplit"
    @remove="handleRemoveSchedule"
/>

</template>

<style scoped>
.master-grid-shell {
    height: calc(100vh - 210px);
    min-height: 420px;
}
</style>
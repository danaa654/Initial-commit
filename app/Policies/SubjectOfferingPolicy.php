<?php

namespace App\Policies;

use App\Models\SubjectOffering;
use App\Models\User;

/**
 * Subject Offerings have no manual create/update/delete path — the only
 * write action is "Generate", gated by its own generate() ability
 * rather than the standard create(). viewAny()/view() cover the Index
 * page; there's deliberately no update()/delete() here since nothing in
 * this module edits or removes an individual offering row (that belongs
 * to whichever future Faculty Loading / Scheduling module manages
 * Confirmed/Cancelled transitions).
 *
 * Registered the same way every other Model/Policy pair in this app is
 * — via Laravel's naming-convention auto-discovery, no explicit
 * AuthServiceProvider entry needed as long as this class lives in
 * App\Policies and is named SubjectOfferingPolicy.
 */
class SubjectOfferingPolicy
{
    /**
     * Who can view the Subject Offerings index/listing.
     *
     * Admin + Registrar only — Dean/Assistant Dean/OIC no longer have
     * any access to this module (they can't see it in the Sidebar, and
     * hitting the route directly now 403s via this same check, mirrored
     * in SubjectOfferingController::middleware()).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'Registrar',
        ]);
    }

    public function view(User $user, SubjectOffering $subjectOffering): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Who can run the "Generate Subject Offerings" action (including
     * regenerating for a term that already has offerings). Currently
     * the same Admin + Registrar set as viewAny() — kept as its own
     * distinct ability (rather than reusing viewAny()) so generate
     * access can be narrowed independently again in the future without
     * having to touch view access.
     */
    public function generate(User $user): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'Registrar',
        ]);
    }
}
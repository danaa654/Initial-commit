<?php

namespace App\Console\Commands;

use App\Services\ActiveSessionService;
use Illuminate\Console\Command;

/**
 * Idle Cleanup — physically deletes any ActiveSession row that has
 * gone past ActiveSession::STALE_AFTER_MINUTES (10) without a fresh
 * touch. LogUserLogout already handles the "clicked Sign Out" case
 * instantly; this command is the safety net for tabs that were just
 * closed, lost connectivity, or crashed without ever hitting the
 * logout route.
 *
 * Note: ActiveUserController's index() query already excludes stale
 * rows via ActiveSession::scopeLive(), so the page never *shows* a
 * stale session even in the minute or so before this command deletes
 * it — this command just keeps the table itself from growing
 * unbounded with dead rows.
 *
 * Register in app/Console/Kernel.php:
 *
 *   protected function schedule(Schedule $schedule): void
 *   {
 *       $schedule->command('active-sessions:prune')->everyMinute();
 *   }
 */
class PruneStaleActiveSessions extends Command
{
    protected $signature = 'active-sessions:prune';

    protected $description = 'Remove active-session rows that have gone stale (10+ minutes of inactivity).';

    public function handle(): int
    {
        $removed = ActiveSessionService::pruneStale();

        $this->info("Pruned {$removed} stale active session(s).");

        return self::SUCCESS;
    }
}
<?php

declare(strict_types=1);

namespace Tetrix\AiBridge\Console;

use Illuminate\Console\Command;
use Tetrix\AiBridge\Streaming\TurnMarker;

/**
 * Clear every conversation's "a turn is running" bookmark (`streaming_request_id`) whose turn
 * is over. Scheduled by the package every five minutes (config
 * `ai-bridge.persistence.sweep_turn_markers`); see TurnMarker::sweep() for what counts as over.
 */
class SweepTurnMarkersCommand extends Command
{
    protected $signature = 'ai-bridge:sweep-turn-markers
        {--grace= : Seconds a bookmark with no buffer is left alone (default: config, 600)}';

    protected $description = 'Clear conversation turn bookmarks whose turn has ended';

    public function handle(): int
    {
        $grace = $this->option('grace');
        $grace = is_numeric($grace)
            ? (int) $grace
            : (int) config('ai-bridge.persistence.turn_marker_grace_seconds', 600);

        $cleared = TurnMarker::sweep($grace);

        $this->info(count($cleared).' stale turn bookmark(s) cleared.');
        foreach ($cleared as $requestId) {
            $this->line('  '.$requestId);
        }

        return self::SUCCESS;
    }
}

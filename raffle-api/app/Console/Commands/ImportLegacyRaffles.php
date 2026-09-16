<?php

namespace App\Console\Commands;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use App\Models\Raffle;
use App\Models\RafflePrizeTier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Imports raffles out of the legacy WordPress custom post type (and their
 * ACF "prize_structure" repeater fields — see the migration that creates
 * raffle_prize_tiers for why that field matters) into the new, native
 * `raffles` / `raffle_prize_tiers` tables.
 *
 * Read-only against wp_posts/wp_postmeta — never writes back to them.
 * Safe to run repeatedly: each raffle is matched by legacy_post_id and
 * its prize tiers are fully replaced on every run, so this always
 * reflects whatever is currently in WordPress rather than drifting from
 * it. Does NOT delete a native raffle if its legacy post disappears.
 */
class ImportLegacyRaffles extends Command
{
    protected $signature = 'legacy:import-raffles {--dry-run}';

    protected $description = 'Import raffles and their prize structures from the legacy WordPress raffle CPT into the native raffles/raffle_prize_tiers tables';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $posts = WpPost::query()->where('post_type', 'raffle')->get();

        if ($posts->isEmpty()) {
            $this->info('No legacy raffle posts found — nothing to import.');

            return self::SUCCESS;
        }

        $postIds = $posts->pluck('ID');

        $metaByPost = WpPostMeta::query()
            ->whereIn('post_id', $postIds)
            ->get()
            ->groupBy('post_id');

        $imported = 0;

        foreach ($posts as $post) {
            $meta = $metaByPost->get($post->ID, collect())->pluck('meta_value', 'meta_key');
            $tiers = $this->parsePrizeStructure($meta);

            $this->line(sprintf(
                '%s Raffle #%d "%s" — price %.2f, max %d, %d prize tier(s)',
                $dryRun ? '[dry-run]' : '[import]',
                $post->ID,
                $post->post_title,
                (float) ($meta->get('price') ?: 0),
                (int) ($meta->get('max') ?: 0),
                count($tiers),
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($post, $meta, $tiers) {
                $raffle = Raffle::query()->updateOrCreate(
                    ['legacy_post_id' => $post->ID],
                    [
                        'title' => $post->post_title,
                        'excerpt' => $post->post_excerpt,
                        'price' => (float) ($meta->get('price') ?: 0),
                        'max_tickets' => (int) ($meta->get('max') ?: 0),
                        'grand_prize' => $meta->get('grand_prize'),
                        'expiry' => $meta->get('expiry') ?: null,
                        'status' => $this->resolveStatus($post->post_status, $meta->get('is_sold_out')),
                    ],
                );

                // Fully replace this raffle's tiers on every import — the
                // legacy ACF data is the source of truth until the
                // draw engine is cut over to reading raffle_prize_tiers
                // directly (item 12).
                $raffle->prizeTiers()->delete();

                foreach ($tiers as $tier) {
                    RafflePrizeTier::create([
                        'raffle_id' => $raffle->id,
                        ...$tier,
                    ]);
                }
            });

            $imported++;
        }

        $this->info($dryRun
            ? "Dry run complete — {$posts->count()} raffle(s) would be imported."
            : "Imported {$imported} raffle(s).");

        return self::SUCCESS;
    }

    private function resolveStatus(string $postStatus, ?string $isSoldOutMeta): string
    {
        if ($isSoldOutMeta === '1') {
            return 'closed';
        }

        return $postStatus === 'publish' ? 'published' : 'draft';
    }

    /**
     * Reconstructs the ACF "prize_structure" repeater's rows from its raw
     * postmeta storage convention: prize_structure_{i}_{field} per row,
     * indexed from 0, with no separate row-count meta relied upon here
     * (the highest index actually present wins) — this is deliberately
     * independent of the ACF plugin itself, since this app doesn't load it.
     *
     * @param  Collection<string, string>  $meta
     * @return array<int, array{tier_name: string, prize_description: ?string, cash_value: float, winner_count: int, rank: int}>
     */
    private function parsePrizeStructure($meta): array
    {
        $rows = [];

        foreach ($meta as $key => $value) {
            if (! preg_match('/^prize_structure_(\d+)_(.+)$/', $key, $m)) {
                continue;
            }

            $rows[(int) $m[1]][$m[2]] = $value;
        }

        ksort($rows);

        $tiers = [];
        $rank = 1;

        foreach ($rows as $row) {
            if (empty($row['tier_name'])) {
                continue; // mirrors the legacy draw's own "skip empty tiers" rule
            }

            $winnerCount = max(1, (int) ($row['winner_count'] ?? 1));

            $tiers[] = [
                'tier_name' => $row['tier_name'],
                'prize_description' => $row['prize_description'] ?? null,
                'cash_value' => (float) ($row['cash_value'] ?? 0),
                'winner_count' => $winnerCount,
                'rank' => $rank,
            ];

            $rank++;
        }

        return $tiers;
    }
}

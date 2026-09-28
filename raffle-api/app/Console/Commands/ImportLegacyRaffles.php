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
 * Does NOT delete a native raffle if its legacy post disappears.
 *
 * Since OVERHAUL_CHECKLIST.md item 43 the native table is the ONLY source
 * of raffles (WordPress is retired), so by default this only ADDS raffles
 * that haven't been imported yet — it used to overwrite every imported
 * raffle and replace its prize tiers on every run, which would now wipe
 * out edits made in the admin. For a raffle already imported it only
 * fills in fields that are still blank (the prize type, prize list and
 * original publish date added in item 43). `--refresh` restores the old
 * overwrite-everything behaviour, for a deliberate re-sync.
 *
 * Each imported raffle keeps its WordPress post id as its permanent
 * public number (`public_id`), so its page address, tickets and winners
 * all still match.
 */
class ImportLegacyRaffles extends Command
{
    protected $signature = 'legacy:import-raffles {--dry-run} {--refresh : Overwrite already-imported raffles (and their prize tiers) with the WordPress values}';

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
        $filled = 0;
        $refresh = (bool) $this->option('refresh');
        $existing = Raffle::query()->whereIn('legacy_post_id', $postIds)->get()->keyBy('legacy_post_id');

        foreach ($posts as $post) {
            $meta = $metaByPost->get($post->ID, collect())->pluck('meta_value', 'meta_key');
            $tiers = $this->parsePrizeStructure($meta);
            $current = $existing->get($post->ID);

            if ($current && ! $refresh) {
                $blanks = $this->blankDisplayFields($current, $post, $meta);

                if ($blanks !== []) {
                    $this->line(sprintf('%s Raffle #%d "%s" — already imported, filling in: %s', $dryRun ? '[dry-run]' : '[fill]', $post->ID, $post->post_title, implode(', ', array_keys($blanks))));

                    if (! $dryRun) {
                        $current->forceFill($blanks)->saveQuietly();
                    }

                    $filled++;
                }

                continue;
            }

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
                $imported++;

                continue;
            }

            DB::transaction(function () use ($post, $meta, $tiers) {
                $raffle = Raffle::query()->updateOrCreate(
                    ['legacy_post_id' => $post->ID],
                    [
                        'public_id' => $post->ID,
                        'title' => $post->post_title,
                        'excerpt' => $post->post_excerpt,
                        'price' => (float) ($meta->get('price') ?: 0),
                        'max_tickets' => (int) ($meta->get('max') ?: 0),
                        'grand_prize' => $meta->get('grand_prize'),
                        'prize_type' => $this->prizeType($meta),
                        'prize_list' => $meta->get('prize_list') ?: null,
                        'expiry' => $meta->get('expiry') ?: null,
                        'status' => $this->resolveStatus($post->post_status, $meta->get('is_sold_out')),
                    ],
                );

                // Keep the original publish date so "newest first" still
                // means what it did on the old site, not "import time".
                if ($post->post_date) {
                    $raffle->forceFill(['created_at' => $post->post_date])->saveQuietly();
                }

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

        if ($filled > 0) {
            $this->info(($dryRun ? 'Would fill in' : 'Filled in')." blank fields on {$filled} already-imported raffle(s).");
        }

        $this->info($dryRun
            ? "Dry run complete — {$imported} raffle(s) would be imported."
            : "Imported {$imported} raffle(s).");

        return self::SUCCESS;
    }

    /**
     * Only the fields item 43 added, and only where still blank — never
     * anything an admin could have deliberately changed.
     *
     * @param  Collection<string, string>  $meta
     * @return array<string, mixed>
     */
    private function blankDisplayFields(Raffle $raffle, WpPost $post, Collection $meta): array
    {
        $fill = [];

        if (($raffle->prize_type ?: 'other') === 'other' && $this->prizeType($meta) !== 'other') {
            $fill['prize_type'] = $this->prizeType($meta);
        }

        if (blank($raffle->prize_list) && filled($meta->get('prize_list'))) {
            $fill['prize_list'] = $meta->get('prize_list');
        }

        if ($raffle->public_id === null) {
            $fill['public_id'] = $post->ID;
        }

        return $fill;
    }

    /** @param  Collection<string, string>  $meta */
    private function prizeType(Collection $meta): string
    {
        $type = (string) $meta->get('prize_type');

        return in_array($type, ['cash', 'gadgets', 'vouchers', 'other'], true) ? $type : 'other';
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

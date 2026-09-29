<?php

namespace App\Services;

use App\Models\HomeItem;
use App\Models\HomeSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the homepage shows, in order. Built from the sections and cards
 * staff arrange in the admin (Site → Homepage). If nothing has been set up
 * yet (or the tables aren't there yet), the original homepage layout is
 * used, so the page can never come up empty.
 */
class HomeLayoutService
{
    /** @return list<array<string, mixed>> */
    public function forVisitor(bool $loggedIn): array
    {
        if (! Schema::hasTable('home_sections') || ! HomeSection::query()->exists()) {
            return $this->publicShape($this->defaults(), $loggedIn);
        }

        $sections = HomeSection::query()->where('is_visible', true)->orderBy('sort_order')->with('items')->get()
            ->map(fn (HomeSection $s) => $s->only(['type', 'title', 'subtitle', 'badge', 'link_label', 'link_url']) + [
                'items' => $s->items->map(fn (HomeItem $i) => $i->attributesToArray() + ['locked_now' => $i->isLockedNow(), 'shown' => $i->isShownTo($loggedIn)])->all(),
            ])->all();

        return $this->publicShape($sections, $loggedIn, fromDatabase: true);
    }

    /** Replace the layout with the original homepage (used by the admin's "Load the default layout" button). */
    public function installDefaults(): void
    {
        DB::transaction(function () {
            HomeItem::query()->delete();
            HomeSection::query()->delete();

            foreach ($this->defaults() as $order => $section) {
                $items = $section['items'] ?? [];
                unset($section['items']);
                $row = HomeSection::create($section + ['sort_order' => $order, 'is_visible' => true]);

                foreach ($items as $n => $item) {
                    $row->items()->create($item + ['sort_order' => $n, 'is_visible' => true]);
                }
            }
        });
    }

    /**
     * Only what the customer's browser needs; drops hidden / out-of-date /
     * wrong-audience cards, and any link that isn't a normal site or web address.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private function publicShape(array $sections, bool $loggedIn, bool $fromDatabase = false): array
    {
        $out = [];

        foreach ($sections as $section) {
            $items = [];

            foreach ($section['items'] ?? [] as $item) {
                $shown = $fromDatabase ? $item['shown'] : true;
                if (! $shown) {
                    continue;
                }

                $locked = $fromDatabase ? $item['locked_now'] : (bool) ($item['is_locked'] ?? false);
                $safe = fn (?string $u) => HomeItem::isSafeLink($u) ? $u : null;

                $items[] = [
                    'title' => $item['title'],
                    'text' => $item['text'] ?? null,
                    'badge' => $item['badge'] ?? null,
                    'icon' => array_key_exists($item['icon'] ?? '', HomeItem::ICONS) ? $item['icon'] : null,
                    'theme' => array_key_exists($item['theme'] ?? '', HomeItem::THEMES) ? $item['theme'] : 'blue',
                    'style' => array_key_exists($item['style'] ?? '', HomeItem::STYLES) ? $item['style'] : 'featured',
                    'size' => ($item['size'] ?? 'half') === 'full' ? 'full' : 'half',
                    'image_url' => $safe($item['image_url'] ?? null),
                    'link_label' => $item['link_label'] ?? null,
                    'link_url' => $locked ? null : $safe($item['link_url'] ?? null),
                    'locked' => $locked,
                    'locked_label' => ! empty($item['locked_label']) ? $item['locked_label'] : 'Coming soon',
                ];
            }

            $type = $section['type'];
            // A slides or cards block with nothing to show is left out entirely.
            if (in_array($type, ['hero', 'cards'], true) && $items === []) {
                continue;
            }

            $out[] = [
                'type' => $type,
                'title' => $section['title'] ?? null,
                'subtitle' => $section['subtitle'] ?? null,
                'badge' => $section['badge'] ?? null,
                'link_label' => $section['link_label'] ?? null,
                'link_url' => HomeItem::isSafeLink($section['link_url'] ?? null) ? $section['link_url'] : null,
                'items' => $items,
            ];
        }

        return $out;
    }

    /** The homepage as it was before it became editable. */
    public function defaults(): array
    {
        return [
            ['type' => 'hero', 'title' => 'Slides', 'items' => [
                ['title' => 'Win Cash Daily!', 'text' => "People are winning right now. Don't wait for Friday!", 'badge' => 'Daily Payouts', 'icon' => 'coins', 'theme' => 'green', 'style' => 'featured', 'link_label' => 'Play for Cash', 'link_url' => '/raffles'],
                ['title' => 'Executive VIP', 'text' => 'Exclusive high-stakes draws for the elite. Only 100 spots.', 'badge' => 'Premium Access', 'icon' => 'crown', 'theme' => 'gold', 'style' => 'featured', 'is_locked' => true, 'locked_label' => 'Coming Soon'],
                ['title' => 'Win a Brand New Car', 'text' => 'Drive away in style. The ultimate grand prize awaits.', 'badge' => 'Dream Ride', 'icon' => 'car', 'theme' => 'red', 'style' => 'featured', 'is_locked' => true, 'locked_label' => 'Coming Soon'],
            ]],
            ['type' => 'golden_box', 'title' => 'Golden Box offer'],
            ['type' => 'cards', 'title' => 'Play & Win', 'badge' => 'Updated Today', 'items' => [
                ['title' => 'Cash Draws', 'text' => 'Win up to ₦500,000 Instantly', 'badge' => 'Active Now', 'icon' => 'banknote', 'theme' => 'blue', 'style' => 'featured', 'size' => 'full', 'link_url' => '/raffles'],
                ['title' => 'Gadgets', 'text' => 'iPhones, Laptops & More', 'icon' => 'smartphone', 'theme' => 'purple', 'style' => 'tile', 'size' => 'half', 'is_locked' => true, 'locked_label' => 'Coming soon'],
                ['title' => 'Grants', 'text' => 'School Fees Support', 'icon' => 'graduation-cap', 'theme' => 'orange', 'style' => 'tile', 'size' => 'half', 'is_locked' => true, 'locked_label' => 'Coming soon'],
                ['title' => 'Top Up Wallet', 'text' => 'Fund your account to play', 'icon' => 'plus', 'theme' => 'gray', 'style' => 'plain', 'size' => 'full', 'link_url' => '/account/wallet'],
            ]],
            ['type' => 'trending', 'title' => 'Trending Now 🔥', 'subtitle' => "Closing soon, don't miss out", 'link_label' => 'See All', 'link_url' => '/raffles'],
        ];
    }
}

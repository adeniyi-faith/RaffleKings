<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What a link to this page looks like when it's shared on WhatsApp,
 * Facebook, X and in search results (item 48): the Open Graph and
 * Twitter tags in resources/views/app.blade.php. Link previews are made
 * by bots that don't run the site's JavaScript, so these have to be in
 * the page the server sends. A route calls PageMeta::set() for its own
 * title and description; every other page gets the site-wide defaults.
 */
final class PageMeta
{
    private array $meta = [];

    public static function set(array $meta): void
    {
        $instance = app(self::class);
        $instance->meta = array_merge($instance->meta, array_filter($meta, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array{title: string, description: string, image: string, url: string, site_name: string, type: string} */
    public function all(): array
    {
        $site = (string) config('app.name', 'RaffleKings');
        $meta = $this->meta + [
            'title' => $site.': Win cash, gadgets and more',
            'description' => 'Pick your lucky numbers and win cash, phones and other prizes in fair, verifiable raffle draws. Join free and get a welcome bonus.',
            'image' => '/images/og-image.png',
            'type' => 'website',
        ];

        return [
            'title' => Str::limit($meta['title'], 90, ''),
            'description' => Str::limit(preg_replace('/\s+/', ' ', strip_tags($meta['description'])), 200),
            'image' => url($meta['image']),
            'url' => $meta['url'] ?? url()->current(),
            'site_name' => $site,
            'type' => $meta['type'],
        ];
    }
}

<?php

namespace App\Services\Guides;

/** Turns a guide (see GuideLibrary) into the page HTML members read and the plain text the support assistant reads. */
class GuideRenderer
{
    public function html(array $guide): string
    {
        $out = [];

        if (isset($guide['blocks'])) {
            foreach ($guide['blocks'] as $block) {
                $out[] = $this->block($block);
            }
        } else {
            if (! empty($guide['intro'])) {
                $out[] = '<p>'.$this->inline($guide['intro']).'</p>';
            }

            if (! empty($guide['image'])) {
                $out[] = $this->figure($guide['image'], $guide['caption'] ?? $guide['title'], $guide['title']);
            }

            if (! empty($guide['steps'])) {
                $out[] = '<h2>Step by step</h2>';
                $out[] = $this->list('ol', $guide['steps']);
            }

            if (! empty($guide['tips'])) {
                $out[] = '<h2>Good to know</h2>';
                $out[] = $this->list('ul', $guide['tips']);
            }
        }

        $out[] = '<p>'.$this->inline($guide['stuck'] ?? 'Still stuck? Open [Help & Support](/support?new=1) and tell us what you see on your screen. A screenshot helps us fix it faster.').'</p>';

        return implode("\n", array_filter($out));
    }

    public function text(array $guide, ?string $url = null): string
    {
        $lines = [];

        if (isset($guide['blocks'])) {
            foreach ($guide['blocks'] as $block) {
                $lines[] = $this->blockText($block);
            }
        } else {
            if (! empty($guide['intro'])) {
                $lines[] = $this->plain($guide['intro']);
            }

            if (! empty($guide['steps'])) {
                $lines[] = "Steps:\n".implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$this->plain($s), $guide['steps'], array_keys($guide['steps'])));
            }

            if (! empty($guide['tips'])) {
                $lines[] = "Good to know:\n".implode("\n", array_map(fn ($t) => '- '.$this->plain($t), $guide['tips']));
            }
        }

        if (! empty($guide['stuck'])) {
            $lines[] = $this->plain($guide['stuck']);
        }

        if ($url) {
            $lines[] = 'Illustrated guide with screenshots for the member: '.$url;
        }

        return trim(implode("\n\n", array_filter($lines)));
    }

    private function block(array $block): string
    {
        return match (true) {
            isset($block['h']) => '<h2>'.$this->inline($block['h']).'</h2>',
            isset($block['p']) => '<p>'.$this->inline($block['p']).'</p>',
            isset($block['ol']) => $this->list('ol', $block['ol']),
            isset($block['ul']) => $this->list('ul', $block['ul']),
            isset($block['note']) => '<div class="rk-callout">'.$this->inline($block['note']).'</div>',
            isset($block['image']) => $this->figure($block['image'], $block['caption'] ?? '', $block['alt'] ?? ($block['caption'] ?? '')),
            default => '',
        };
    }

    private function blockText(array $block): string
    {
        return match (true) {
            isset($block['h']) => $this->plain($block['h']).':',
            isset($block['p']) => $this->plain($block['p']),
            isset($block['note']) => 'Note: '.$this->plain($block['note']),
            isset($block['ol']) => implode("\n", array_map(fn ($s, $i) => ($i + 1).'. '.$this->plain($s), $block['ol'], array_keys($block['ol']))),
            isset($block['ul']) => implode("\n", array_map(fn ($t) => '- '.$this->plain($t), $block['ul'])),
            default => '',
        };
    }

    private function list(string $tag, array $items): string
    {
        return "<{$tag}>".implode('', array_map(fn ($i) => '<li>'.$this->inline($i).'</li>', $items))."</{$tag}>";
    }

    private function figure(string $image, string $caption, string $alt): string
    {
        $src = '/guides/'.$image.'.jpg';

        if (! is_file(public_path($src))) {
            return '';
        }

        $figcaption = $caption !== '' ? '<figcaption>'.e($caption).'</figcaption>' : '';

        return '<figure><img src="'.e($src).'" alt="'.e($alt).'" loading="lazy" />'.$figcaption.'</figure>';
    }

    /** Escapes the text, then turns **bold** and [label](/path) into HTML. */
    private function inline(string $text): string
    {
        $text = e($text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);

        return preg_replace_callback('/\[([^\]]+)\]\(((?:\/|https:\/\/)[^)\s]*)\)/', fn (array $m) => '<a href="'.$m[2].'">'.$m[1].'</a>', $text);
    }

    private function plain(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);

        return preg_replace('/\[([^\]]+)\]\(([^)\s]*)\)/', '$1 ($2)', $text);
    }
}

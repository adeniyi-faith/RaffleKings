<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\Tutorial;
use App\Services\Ai\KnowledgeBase;
use App\Services\Guides\GuideLibrary;
use App\Services\Guides\GuideParser;
use App\Services\Guides\GuideRenderer;
use App\Services\Guides\GuideSync;
use App\Support\GuideTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The built-in help guides: the files are sound, they sync to the Learning Hub and the support assistant, and read well. */
class HelpGuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_files_are_complete_and_cross_link_correctly(): void
    {
        $guides = app(GuideLibrary::class)->all();
        $keys = array_column($guides, 'key');

        $this->assertGreaterThanOrEqual(100, count($guides));
        $this->assertSame($keys, array_values(array_unique($keys)), 'Guide keys must be unique.');

        $raw = collect(glob(database_path('guides/*.md')))->map(fn ($f) => file_get_contents($f))->implode("\n");

        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/', $raw, $tokens);
        $this->assertSame([], array_diff(array_unique($tokens[1]), array_keys(GuideTokens::values())), 'Unknown {{placeholders}}.');

        preg_match_all('#\(/support/tutorials/([a-z0-9-]+)\)#', $raw, $links);
        $this->assertSame([], array_diff(array_unique($links[1]), $keys), 'A guide links to a guide that does not exist.');

        foreach ($guides as $guide) {
            $this->assertContains($guide['category'], Tutorial::CATEGORIES, "{$guide['key']} has an unknown category.");

            if (! empty($guide['image'])) {
                $this->assertFileExists(public_path("guides/{$guide['image']}.jpg"), "{$guide['key']} points at a missing screenshot.");
            }
        }

        $this->assertCount(1, array_filter($guides, fn ($g) => ! empty($g['featured'])), 'Exactly one guide should be the "start here" guide.');
    }

    public function test_syncing_creates_each_guide_once_with_a_knowledge_twin_and_even_hearts(): void
    {
        $total = count(app(GuideLibrary::class)->all());

        $first = app(GuideSync::class)->run();
        $second = app(GuideSync::class)->run();

        $this->assertSame($total, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame($total, Tutorial::query()->whereNotNull('guide_key')->count());
        $this->assertSame($total, KnowledgeArticle::query()->where('source_file', 'like', 'guide:%')->where('is_active', true)->count());

        $hearts = Tutorial::query()->whereNotNull('guide_key')->pluck('helpful_count');
        $this->assertGreaterThanOrEqual(30, $hearts->min());
        $this->assertLessThanOrEqual(45, $hearts->max());
    }

    public function test_a_refresh_overwrites_staff_edits_but_a_normal_sync_keeps_them(): void
    {
        app(GuideSync::class)->run();
        $guide = Tutorial::query()->where('guide_key', 'how-to-withdraw-your-winnings')->firstOrFail();
        $guide->update(['content' => '<p>Staff wrote this</p>', 'helpful_count' => 99]);

        app(GuideSync::class)->run();
        $this->assertSame('<p>Staff wrote this</p>', $guide->fresh()->content);

        app(GuideSync::class)->run(refresh: true);
        $this->assertStringContainsString('Step by step', $guide->fresh()->content);
        $this->assertSame(99, $guide->fresh()->helpful_count, 'Hearts people gave are never reset.');
    }

    public function test_placeholders_follow_the_live_settings(): void
    {
        config(['withdrawals.minimum_amount' => 5000]);

        $this->assertSame('The smallest withdrawal is ₦5,000.', GuideTokens::fill('The smallest withdrawal is {{min_withdrawal}}.'));
        $this->assertSame('Keep {{not_a_real_token}} as is', GuideTokens::fill('Keep {{not_a_real_token}} as is'));
    }

    public function test_the_parser_reads_the_simple_and_the_free_layout(): void
    {
        $guides = (new GuideParser)->parse("=== a-guide\ncategory: Deposits\ntitle: A guide\nexcerpt: Short\n\nIntro line.\n\n1. One.\n   carries on.\n2. Two.\n\nGood to know:\n- A tip.\n\nStuck: Ask us.\n\n=== free-layout\ncategory: Deposits\ntitle: Free\nexcerpt: Short\n\n## Heading\n\n> A note\n\n!image: home-top | Cap\n");

        $this->assertSame(['One. carries on.', 'Two.'], $guides[0]['steps']);
        $this->assertSame(['A tip.'], $guides[0]['tips']);
        $this->assertSame('Ask us.', $guides[0]['stuck']);
        $this->assertSame('Heading', $guides[1]['blocks'][0]['h']);
        $this->assertSame('A note', $guides[1]['blocks'][1]['note']);
        $this->assertSame('home-top', $guides[1]['blocks'][2]['image']);
    }

    public function test_rendering_escapes_text_and_makes_links_and_bold(): void
    {
        $html = app(GuideRenderer::class)->html(['key' => 'x', 'title' => 'T', 'intro' => '<script>alert(1)</script> **bold** [Help](/support?new=1)']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<a href="/support?new', $html);
    }

    public function test_the_hub_lists_every_guide_without_dates_and_a_guide_opens_by_its_short_name(): void
    {
        app(GuideSync::class)->run();

        $this->get('/support/tutorials/how-to-withdraw-your-winnings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Support/Tutorial')
                ->where('tutorial.date_ago', null)
                ->where('tutorial.title', 'How to withdraw your winnings to your bank'));

        $this->get('/support/tutorials/not-a-guide')->assertNotFound();
    }

    public function test_the_support_assistant_reads_guides_with_their_numbers_filled_in(): void
    {
        config(['withdrawals.minimum_amount' => 7500]);
        app(GuideSync::class)->run();

        $context = app(KnowledgeBase::class)->contextFor('what is the minimum withdrawal amount');

        $this->assertStringContainsString('₦7,500', $context);
        $this->assertStringNotContainsString('{{min_withdrawal}}', $context);
    }
}

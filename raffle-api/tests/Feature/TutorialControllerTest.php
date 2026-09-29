<?php

namespace Tests\Feature;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use App\Models\Tutorial;
use App\Services\Legacy\TutorialImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorialControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTutorial(array $attributes = []): Tutorial
    {
        return Tutorial::create(array_merge([
            'title' => 'How to Play & Win',
            'excerpt' => 'A 3-step guide.',
            'content' => '<p>Full guide content.</p>',
            'published_at' => now()->subMinute(),
        ], $attributes));
    }

    public function test_it_lists_published_tutorials_with_the_featured_one_pulled_out(): void
    {
        $featured = $this->makeTutorial(['is_featured' => true, 'video_url' => 'https://youtube.com/watch?v=abc']);
        $regular = $this->makeTutorial(['category' => 'Strategy', 'helpful_count' => 5]);
        $this->makeTutorial(['is_published' => false]);
        $this->makeTutorial(['published_at' => now()->addDay()]);

        $response = $this->getJson('/api/tutorials')->assertOk();

        $response->assertJson(['featured' => ['id' => $featured->id, 'video_url' => 'https://youtube.com/watch?v=abc']]);
        $response->assertJsonCount(1, 'list');
        $response->assertJsonFragment(['id' => $regular->id, 'category' => 'Strategy', 'helpful_count' => 5]);
    }

    public function test_marking_a_tutorial_helpful_increments_its_count(): void
    {
        $tutorial = $this->makeTutorial(['helpful_count' => 3]);

        $this->postJson("/api/tutorials/{$tutorial->id}/helpful")->assertOk()->assertJson(['new_count' => 4]);
    }

    public function test_marking_an_unknown_or_hidden_tutorial_helpful_returns_404(): void
    {
        $this->postJson('/api/tutorials/99999/helpful')->assertStatus(404);

        $hidden = $this->makeTutorial(['is_published' => false]);
        $this->postJson("/api/tutorials/{$hidden->id}/helpful")->assertStatus(404);
    }

    public function test_scripts_and_unsafe_links_never_reach_the_site(): void
    {
        $this->makeTutorial([
            'content' => '<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
            'video_url' => 'javascript:alert(1)',
        ]);

        $item = $this->getJson('/api/tutorials')->json('list.0');

        $this->assertStringNotContainsString('<script', $item['content']);
        $this->assertStringNotContainsString('onclick', $item['content']);
        $this->assertStringNotContainsString('javascript:', $item['content']);
        $this->assertNull($item['video_url']);
    }

    public function test_old_wordpress_tutorials_are_copied_across_with_their_ids(): void
    {
        $post = WpPost::create([
            'post_title' => 'Old guide', 'post_excerpt' => 'From WordPress', 'post_content' => '<p>Old content</p>',
            'post_type' => 'tutorial', 'post_status' => 'publish', 'post_date' => now()->subYear(),
        ]);
        foreach (['is_featured' => '1', 'category_badge' => 'Payments', 'helpful_count' => '7'] as $key => $value) {
            WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => $key, 'meta_value' => $value]);
        }
        WpPost::create(['post_title' => 'A raffle', 'post_type' => 'raffle', 'post_status' => 'publish', 'post_date' => now()]);

        $this->assertSame(1, app(TutorialImporter::class)->import());
        $this->assertSame(0, app(TutorialImporter::class)->import(), 'Running it again copies nothing twice.');

        $tutorial = Tutorial::findOrFail($post->ID);
        $this->assertSame('Payments', $tutorial->category);
        $this->assertTrue($tutorial->is_featured);
        $this->assertSame(7, $tutorial->helpful_count);
        $this->postJson("/api/tutorials/{$post->ID}/helpful")->assertJson(['new_count' => 8]);
    }
}

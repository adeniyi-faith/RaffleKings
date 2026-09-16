<?php

namespace Tests\Feature;

use App\Models\Legacy\WpPost;
use App\Models\Legacy\WpPostMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorialControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeTutorial(array $meta = [], string $status = 'publish'): WpPost
    {
        $post = WpPost::create([
            'post_title' => 'How to Play & Win',
            'post_excerpt' => 'A 3-step guide.',
            'post_content' => '<p>Full guide content.</p>',
            'post_type' => 'tutorial',
            'post_status' => $status,
            'post_date' => now(),
        ]);

        foreach ($meta as $key => $value) {
            WpPostMeta::create(['post_id' => $post->ID, 'meta_key' => $key, 'meta_value' => $value]);
        }

        return $post;
    }

    public function test_it_lists_published_tutorials_with_the_featured_one_pulled_out(): void
    {
        $featured = $this->makeTutorial(['is_featured' => '1', 'video_url' => 'https://youtube.com/watch?v=abc']);
        $regular = $this->makeTutorial(['category_badge' => 'Strategy', 'helpful_count' => '5']);
        $this->makeTutorial(status: 'draft');

        $response = $this->getJson('/api/tutorials')->assertOk();

        $response->assertJson(['featured' => ['id' => $featured->ID]]);
        $response->assertJsonCount(1, 'list');
        $response->assertJsonFragment(['id' => $regular->ID, 'category' => 'Strategy', 'helpful_count' => 5]);
    }

    public function test_marking_a_tutorial_helpful_increments_its_count(): void
    {
        $tutorial = $this->makeTutorial(['helpful_count' => '3']);

        $response = $this->postJson("/api/tutorials/{$tutorial->ID}/helpful")->assertOk();

        $response->assertJson(['new_count' => 4]);
    }

    public function test_marking_an_unknown_or_non_tutorial_post_helpful_returns_404(): void
    {
        $this->postJson('/api/tutorials/99999/helpful')->assertStatus(404);

        $raffle = WpPost::create([
            'post_title' => 'Not a tutorial', 'post_type' => 'raffle', 'post_status' => 'publish', 'post_date' => now(),
        ]);

        $this->postJson("/api/tutorials/{$raffle->ID}/helpful")->assertStatus(404);
    }
}

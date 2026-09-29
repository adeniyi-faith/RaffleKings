<?php

namespace Tests\Feature;

use App\Models\HomeItem;
use App\Models\HomeSection;
use App\Services\HomeLayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_nothing_set_up_the_original_homepage_is_used(): void
    {
        $layout = app(HomeLayoutService::class)->forVisitor(false);

        $this->assertSame(['hero', 'golden_box', 'cards', 'trending'], array_column($layout, 'type'));
        $this->assertCount(3, $layout[0]['items']);
    }

    public function test_sections_come_back_in_the_order_staff_set(): void
    {
        HomeSection::create(['type' => 'trending', 'title' => 'Second', 'sort_order' => 2]);
        HomeSection::create(['type' => 'cards', 'title' => 'First', 'sort_order' => 1])
            ->items()->create(['title' => 'Card', 'sort_order' => 0]);
        HomeSection::create(['type' => 'cards', 'title' => 'Hidden', 'sort_order' => 0, 'is_visible' => false]);

        $layout = app(HomeLayoutService::class)->forVisitor(false);

        $this->assertSame(['First', 'Second'], array_column($layout, 'title'));
    }

    public function test_a_locked_card_has_no_link_and_unlocks_by_itself_on_its_date(): void
    {
        $section = HomeSection::create(['type' => 'cards', 'sort_order' => 0]);
        $section->items()->create(['title' => 'Locked', 'link_url' => '/raffles', 'is_locked' => true, 'sort_order' => 0]);
        $section->items()->create(['title' => 'Opened', 'link_url' => '/raffles', 'is_locked' => true, 'unlock_at' => now()->subMinute(), 'sort_order' => 1]);
        $section->items()->create(['title' => 'Soon', 'link_url' => '/raffles', 'is_locked' => true, 'unlock_at' => now()->addDay(), 'sort_order' => 2]);

        $items = collect(app(HomeLayoutService::class)->forVisitor(false)[0]['items'])->keyBy('title');

        $this->assertTrue($items['Locked']['locked']);
        $this->assertNull($items['Locked']['link_url']);
        $this->assertFalse($items['Opened']['locked']);
        $this->assertSame('/raffles', $items['Opened']['link_url']);
        $this->assertTrue($items['Soon']['locked']);
    }

    public function test_audience_and_schedule_decide_who_sees_a_card(): void
    {
        $section = HomeSection::create(['type' => 'cards', 'sort_order' => 0]);
        $section->items()->create(['title' => 'Everyone', 'sort_order' => 0]);
        $section->items()->create(['title' => 'Guests', 'audience' => 'guests', 'sort_order' => 1]);
        $section->items()->create(['title' => 'Members', 'audience' => 'members', 'sort_order' => 2]);
        $section->items()->create(['title' => 'Later', 'starts_at' => now()->addDay(), 'sort_order' => 3]);
        $section->items()->create(['title' => 'Over', 'ends_at' => now()->subDay(), 'sort_order' => 4]);
        $section->items()->create(['title' => 'Off', 'is_visible' => false, 'sort_order' => 5]);

        $service = app(HomeLayoutService::class);

        $this->assertSame(['Everyone', 'Guests'], array_column($service->forVisitor(false)[0]['items'], 'title'));
        $this->assertSame(['Everyone', 'Members'], array_column($service->forVisitor(true)[0]['items'], 'title'));
    }

    public function test_an_unsafe_link_is_dropped(): void
    {
        $section = HomeSection::create(['type' => 'cards', 'sort_order' => 0]);
        $section->items()->create(['title' => 'Bad', 'link_url' => 'javascript:alert(1)', 'image_url' => '//evil.example/x.png', 'sort_order' => 0]);

        $item = app(HomeLayoutService::class)->forVisitor(false)[0]['items'][0];

        $this->assertNull($item['link_url']);
        $this->assertNull($item['image_url']);
    }

    public function test_install_defaults_copies_the_original_layout(): void
    {
        app(HomeLayoutService::class)->installDefaults();

        $this->assertSame(4, HomeSection::count());
        $this->assertSame(7, HomeItem::count());
    }
}

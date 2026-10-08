<?php

namespace Tests\Feature;

use App\Filament\Resources\AdResource\Pages\CreateAd;
use App\Filament\Resources\AdResource\Pages\ListAds;
use App\Models\Ads\Ad;
use App\Models\Ads\AdStat;
use App\Models\Ads\AdViewer;
use App\Models\Legacy\RaffleEntry;
use App\Models\Legacy\WpUser;
use App\Models\PlayLimit;
use App\Models\Retention\MemberProfile;
use App\Services\Ads\AdServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/** The on-site ads engine (Site → Ads, App\Services\Ads\AdServer). */
class AdsEngineTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function ad(array $attributes = [], array $variants = [['label' => 'A', 'title' => 'Earn as an affiliate']]): Ad
    {
        $ad = Ad::create(array_merge([
            'name' => 'Affiliate push',
            'status' => 'live',
            'placements' => ['rewards'],
            'target_type' => 'page',
            'target' => '/affiliate',
        ], $attributes));

        foreach ($variants as $i => $variant) {
            $ad->variants()->create($variant + ['sort_order' => $i]);
        }

        return $ad->fresh('variants');
    }

    private function member(): WpUser
    {
        return WpUser::create(['user_login' => uniqid('m'), 'user_pass' => 'x', 'user_email' => uniqid().'@example.com', 'display_name' => 'Ada']);
    }

    private function pick(array $slots, ?WpUser $user = null, string $client = 'browser-123', ?string $path = null): array
    {
        AdServer::forgetCache();

        return app(AdServer::class)->pick($slots, $user, $client, '127.0.0.1', $path);
    }

    public function test_a_live_ad_shows_in_its_spot_with_a_signed_token(): void
    {
        $ad = $this->ad();

        $this->getJson('/api/ads?slots=rewards,wallet&v=browser-123')
            ->assertOk()
            ->assertJsonPath('ads.rewards.0.title', 'Earn as an affiliate')
            ->assertJsonPath('ads.rewards.0.href', '/affiliate')
            ->assertJsonPath('ads.rewards.0.external', false)
            ->assertJsonMissingPath('ads.wallet');

        $token = $this->getJson('/api/ads?slots=rewards')->json('ads.rewards.0.token');
        $this->assertSame([$ad->id, $ad->variants[0]->id, 'rewards'], AdServer::readToken($token));
        $this->assertNull(AdServer::readToken(str_replace('rewards', 'wallet', $token)));
    }

    public function test_drafts_paused_unscheduled_and_ended_ads_never_show(): void
    {
        $this->ad(['name' => 'Draft', 'status' => 'draft']);
        $this->ad(['name' => 'Paused', 'status' => 'paused']);
        $this->ad(['name' => 'Later', 'starts_at' => now()->addDay()]);
        $this->ad(['name' => 'Over', 'ends_at' => now()->subMinute()]);

        $this->assertSame([], $this->pick(['rewards']));
    }

    public function test_an_outside_link_must_be_https_and_gets_tracking_tags(): void
    {
        $this->ad(['target_type' => 'url', 'target' => 'https://partner.example/offer?x=1', 'utm_campaign' => 'partner-oct'], [['label' => 'B', 'title' => 'Partner deal']]);
        $this->ad(['name' => 'Unsafe', 'target_type' => 'url', 'target' => 'javascript:alert(1)']);

        $ads = $this->pick(['rewards'])['rewards'];

        $this->assertCount(1, $ads);
        $this->assertTrue($ads[0]['external']);
        $this->assertStringStartsWith('https://partner.example/offer?x=1&utm_source=', $ads[0]['href']);
        $this->assertStringContainsString('utm_medium=onsite_ad', $ads[0]['href']);
        $this->assertStringContainsString('utm_campaign=partner-oct', $ads[0]['href']);
        $this->assertStringContainsString('utm_content=B', $ads[0]['href']);
    }

    public function test_audiences_decide_who_sees_an_ad(): void
    {
        $this->ad(['name' => 'Everyone']);
        $this->ad(['name' => 'Members', 'audience' => 'members'], [['title' => 'Members']]);
        $this->ad(['name' => 'Guests', 'audience' => 'guests'], [['title' => 'Guests']]);
        $this->ad(['name' => 'Drifting', 'audience' => 'groups', 'groups' => ['drifting']], [['title' => 'Come back']]);

        $titles = fn (?WpUser $u) => collect($this->pick(['rewards'], $u)['rewards'] ?? [])->pluck('title')->sort()->values()->all();

        $this->assertSame(['Earn as an affiliate', 'Guests'], $titles(null));

        $member = $this->member();
        $this->assertSame(['Earn as an affiliate', 'Members'], $titles($member));

        MemberProfile::query()->forceCreate(['user_id' => $member->ID, 'segment' => 'drifting']);
        $this->assertSame(['Come back', 'Earn as an affiliate', 'Members'], $titles($member));
    }

    public function test_nobody_on_a_break_from_playing_sees_ads(): void
    {
        $this->ad();
        $member = $this->member();
        PlayLimit::query()->forceCreate(['user_id' => $member->ID, 'excluded_until' => now()->addDays(7)]);

        $this->assertSame([], $this->pick(['rewards'], $member));
    }

    public function test_an_ad_for_the_page_someone_is_on_is_skipped(): void
    {
        $this->ad(['placements' => ['rewards', 'home_top'], 'target' => '/rewards']);

        $this->assertSame([], $this->pick(['rewards'], path: '/rewards'));
        $this->assertCount(1, $this->pick(['home_top'], path: '/')['home_top']);
    }

    public function test_views_taps_and_closes_are_counted_and_the_daily_limit_per_person_works(): void
    {
        $ad = $this->ad(['per_person_daily' => 2]);
        $token = $this->pick(['rewards'])['rewards'][0]['token'];

        $this->postJson('/api/ads/event', ['t' => $token, 'e' => 'view', 'v' => 'browser-123'])->assertNoContent();
        $this->postJson('/api/ads/event', ['t' => $token, 'e' => 'click', 'v' => 'browser-123'])->assertNoContent();
        $this->postJson('/api/ads/event', ['t' => $token, 'e' => 'close', 'v' => 'browser-123'])->assertNoContent();
        $this->postJson('/api/ads/event', ['t' => 'forged', 'e' => 'view'])->assertNoContent();

        $stat = AdStat::query()->where('ad_id', $ad->id)->sole();
        $this->assertSame([1, 1, 1], [$stat->views, $stat->clicks, $stat->closes]);
        $this->assertNotNull(AdViewer::query()->where('ad_id', $ad->id)->sole()->first_click_at);

        // One more view reaches this browser's limit of 2 a day; another browser still sees it.
        $this->postJson('/api/ads/event', ['t' => $token, 'e' => 'view', 'v' => 'browser-123']);
        $this->assertSame([], $this->pick(['rewards'], client: 'browser-123'));
        $this->assertNotEmpty($this->pick(['rewards'], client: 'browser-456'));
    }

    public function test_total_and_daily_view_limits_stop_an_ad(): void
    {
        $ad = $this->ad(['total_views_cap' => 2]);
        $token = $this->pick(['rewards'])['rewards'][0]['token'];
        $server = app(AdServer::class);

        $server->record($token, 'view', null, 'one-browser', null);
        $this->assertNotEmpty($this->pick(['rewards']));
        $server->record($token, 'view', null, 'two-browser', null);
        $this->assertSame([], $this->pick(['rewards']));

        $ad->update(['total_views_cap' => null, 'daily_views_cap' => 2]);
        $this->assertSame([], $this->pick(['rewards']));
    }

    public function test_each_person_keeps_the_same_version_and_versions_split_people(): void
    {
        $this->ad([], [['label' => 'A', 'title' => 'Version A'], ['label' => 'B', 'title' => 'Version B']]);

        $seen = collect(range(1, 40))->map(fn ($i) => $this->pick(['rewards'], client: "browser-{$i}xxxx")['rewards'][0]['title']);

        $this->assertEqualsCanonicalizing(['Version A', 'Version B'], $seen->unique()->values()->all());
        $this->assertSame($this->pick(['rewards'], client: 'browser-7xxxx')['rewards'][0]['title'], $this->pick(['rewards'], client: 'browser-7xxxx')['rewards'][0]['title']);
    }

    public function test_the_report_counts_members_who_tapped_then_bought(): void
    {
        $ad = $this->ad();
        $buyer = $this->member();
        $browser = $this->member();
        $token = $this->pick(['rewards'], $buyer)['rewards'][0]['token'];
        $server = app(AdServer::class);

        foreach ([$buyer, $browser] as $who) {
            $server->record($token, 'view', $who, null, null);
            $server->record($token, 'click', $who, null, null);
        }
        RaffleEntry::forceCreate(['user_id' => $buyer->ID, 'raffle_id' => 1, 'ticket_number' => 1, 'txn_id' => 0, 'created_at' => now()->addHour()]);

        $report = $server->report($ad->fresh('variants'));

        $this->assertSame(2, $report['total']['views']);
        $this->assertSame(100.0, $report['total']['rate']);
        $this->assertSame(2, $report['tappers']);
        $this->assertSame(1, $report['bought_after']);
        $this->assertSame('A', $report['variants'][0]['label']);
    }

    public function test_the_master_switch_hides_every_ad(): void
    {
        $this->ad();
        config(['ads.enabled' => false]);

        $this->assertSame([], $this->pick(['rewards']));
    }

    public function test_staff_can_create_an_ad_and_it_is_logged(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateAd::class)
            ->fillForm([
                'name' => 'Daily rewards push',
                'status' => 'draft',
                'placements' => ['home_top', 'popup'],
                'look' => 'card',
                'priority' => 5,
                'target_type' => 'page',
                'target' => '/rewards',
                'audience' => 'all',
                'variants' => [['label' => 'A', 'title' => 'Claim your daily reward', 'weight' => 1, 'theme' => 'green']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ad = Ad::query()->with('variants')->sole();
        $this->assertSame(['home_top', 'popup'], $ad->placements);
        $this->assertSame('Claim your daily reward', $ad->variants->sole()->title);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'ad.created', 'subject_id' => $ad->id]);

        Livewire::test(ListAds::class)->assertCanSeeTableRecords([$ad])->callTableAction('golive', $ad);
        $this->assertSame('live', $ad->fresh()->status);
    }

    public function test_an_outside_link_without_https_is_refused_in_the_form(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreateAd::class)
            ->fillForm([
                'name' => 'Bad link', 'status' => 'draft', 'placements' => ['rewards'], 'look' => 'card', 'priority' => 5,
                'target_type' => 'url', 'target' => 'http://insecure.example', 'audience' => 'all',
                'variants' => [['label' => 'A', 'title' => 'x', 'weight' => 1, 'theme' => 'green']],
            ])
            ->call('create')
            ->assertHasFormErrors(['target']);
    }

    /** Puts views and taps straight into the daily totals. */
    private function stats(Ad $ad, string $label, int $views, int $clicks): void
    {
        AdStat::query()->create(['ad_id' => $ad->id, 'ad_variant_id' => $ad->variants->firstWhere('label', $label)->id, 'placement' => 'rewards', 'day' => now()->toDateString(), 'views' => $views, 'clicks' => $clicks, 'closes' => 0]);
    }

    public function test_automatic_switching_moves_everyone_to_a_clear_winner(): void
    {
        $ad = $this->ad(['auto_winner' => true, 'auto_winner_min_views' => 500], [['label' => 'A', 'title' => 'Version A'], ['label' => 'B', 'title' => 'Version B']]);
        $this->stats($ad, 'A', 1000, 20);
        $this->stats($ad, 'B', 1000, 60);

        $this->assertSame(1, app(AdServer::class)->pickWinners());

        $ad->refresh()->load('variants');
        $this->assertSame($ad->variants->firstWhere('label', 'B')->id, $ad->winner_variant_id);
        $this->assertSame(0, $ad->variants->firstWhere('label', 'A')->weight);
        $this->assertNotNull($ad->winner_picked_at);

        $seen = collect(range(1, 20))->map(fn ($i) => $this->pick(['rewards'], client: "browser-{$i}xxxx")['rewards'][0]['title'])->unique()->values()->all();
        $this->assertSame(['Version B'], $seen);
    }

    public function test_no_winner_with_too_few_views_a_close_race_or_switching_off(): void
    {
        $few = $this->ad(['name' => 'Few', 'auto_winner' => true, 'auto_winner_min_views' => 500], [['label' => 'A', 'title' => 'A'], ['label' => 'B', 'title' => 'B']]);
        $this->stats($few, 'A', 100, 1);
        $this->stats($few, 'B', 100, 30);

        $close = $this->ad(['name' => 'Close', 'auto_winner' => true, 'auto_winner_min_views' => 500], [['label' => 'A', 'title' => 'A'], ['label' => 'B', 'title' => 'B']]);
        $this->stats($close, 'A', 1000, 50);
        $this->stats($close, 'B', 1000, 55);

        $off = $this->ad(['name' => 'Off', 'auto_winner' => false], [['label' => 'A', 'title' => 'A'], ['label' => 'B', 'title' => 'B']]);
        $this->stats($off, 'A', 1000, 20);
        $this->stats($off, 'B', 1000, 60);

        $this->assertSame(0, app(AdServer::class)->pickWinners());
        $this->assertNull($few->fresh()->winner_variant_id);
        $this->assertNull($close->fresh()->winner_variant_id);
        $this->assertNull($off->fresh()->winner_variant_id);
    }

    public function test_switching_automatic_picking_back_on_starts_a_fresh_test(): void
    {
        $ad = $this->ad(['auto_winner' => false, 'winner_variant_id' => 99, 'winner_picked_at' => now()]);

        $ad->update(['auto_winner' => true]);

        $this->assertNull($ad->fresh()->winner_variant_id);
        $this->assertNull($ad->fresh()->winner_picked_at);
    }
}

<?php

namespace Tests\Feature;

use App\Auth\StaffRoles;
use App\Filament\Support\AdminSearch;
use App\Models\Growth\PromoCode;
use App\Models\Legacy\WpUserMeta;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

class AdminSearchAndAvatarTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** What the top-bar search box finds, as the signed-in staff member. */
    private function results(string $query): array
    {
        return Livewire::test(GlobalSearch::class)->set('search', $query)->instance()->getResults()?->getCategories()->all() ?? [];
    }

    /** @return array<string, list<string>> category => result titles */
    private function search(string $query): array
    {
        return collect($this->results($query))
            ->map(fn ($results) => collect($results)->map(fn (GlobalSearchResult $r) => (string) $r->title)->values()->all())
            ->all();
    }

    private function urlFor(string $query, string $title): ?string
    {
        foreach ($this->results($query) as $results) {
            foreach ($results as $result) {
                if ((string) $result->title === $title) {
                    return $result->url;
                }
            }
        }

        return null;
    }

    public function test_the_top_bar_shows_the_staff_members_own_picture_or_their_initials(): void
    {
        $admin = $this->actingAsAdministrator();
        $this->assertNull($admin->getFilamentAvatarUrl());
        $this->get('/admin')->assertOk()->assertSee('ui-avatars.com', false);

        WpUserMeta::create(['user_id' => $admin->ID, 'meta_key' => 'profile_pic_url', 'meta_value' => '/storage/avatars/me.jpg']);
        $this->assertSame('/storage/avatars/me.jpg', $admin->fresh()->getFilamentAvatarUrl());
        $this->get('/admin')->assertOk()->assertSee('/storage/avatars/me.jpg', false);
    }

    public function test_it_finds_things_to_do_in_plain_words(): void
    {
        $this->actingAsAdministrator();

        $this->assertContains('Pay a withdrawal (mark paid)', $this->search('pay withdrawal')['Do something']);
        $this->assertContains('Turn maintenance mode on or off', $this->search('maintenance')['Do something']);
        $this->assertContains('Ban, restrict or adjust a customer\'s balance', $this->search('ban customer')['Do something']);
        $this->assertStringEndsWith('/admin/promo-codes/create', $this->urlFor('coupon', 'Create a promo code'));
    }

    public function test_it_finds_admin_pages_and_settings_on_the_right_tab(): void
    {
        $this->actingAsAdministrator();

        $this->assertContains('Fraud watch', $this->search('fraud')['Admin pages']);

        $this->assertContains('Secret key', $this->search('paystack secret')['Settings']);
        $url = $this->urlFor('paystack secret', 'Secret key');
        $this->assertStringContainsString('tab=settings-payments-tab', $url);
        $this->assertStringEndsWith('#data.services__paystack__secret_key', $url);

        $this->assertContains('Smallest withdrawal', $this->search('smallest withdrawal')['Settings']);
    }

    public function test_it_finds_records_too(): void
    {
        $this->actingAsAdministrator();
        $this->createRaffle(['title' => 'Toyota Camry Giveaway']);
        PromoCode::create(['code' => 'TOBI10', 'kind' => 'ticket_discount', 'percent_off' => 10]);

        $this->assertContains('Toyota Camry Giveaway', $this->search('camry')['Raffles']);
        $this->assertContains('TOBI10', $this->search('tobi10')['Promo codes']);
    }

    public function test_staff_only_see_what_their_role_can_open(): void
    {
        $staff = $this->actingAsWordPressUser();
        WpUserMeta::create(['user_id' => $staff->ID, 'meta_key' => StaffRoles::META_KEY, 'meta_value' => 'content']);
        Livewire::withCookies($this->unencryptedCookies);

        $results = $this->search('withdrawal');
        $this->assertArrayNotHasKey('Do something', $results);
        $this->assertArrayNotHasKey('Settings', $results);

        $this->assertContains('Post an announcement on the site', $this->search('announcement')['Do something']);
    }

    public function test_the_search_box_in_the_top_bar_uses_it(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(GlobalSearch::class)
            ->set('search', 'maintenance')
            ->assertSee('Turn maintenance mode on or off')
            ->assertSee('Maintenance mode on now');
    }
}

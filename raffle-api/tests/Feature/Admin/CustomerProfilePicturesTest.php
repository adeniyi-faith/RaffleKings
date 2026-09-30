<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Legacy\WpUserResource\Pages\ListWpUsers;
use App\Filament\Resources\Legacy\WpUserResource\Pages\ViewWpUser;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * Staff see each customer's profile picture in the customers list (desktop
 * table and phone cards) and at the top of the customer's profile. A customer
 * with no picture gets their initials instead of a blank or broken image.
 */
class CustomerProfilePicturesTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function customer(string $name, ?string $photo = null): WpUser
    {
        $user = WpUser::create([
            'user_login' => strtolower(str_replace(' ', '', $name)).uniqid(),
            'user_pass' => 'x',
            'user_email' => uniqid().'@example.com',
            'display_name' => $name,
        ]);

        if ($photo !== null) {
            WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'profile_pic_url', 'meta_value' => $photo]);
        }

        return $user;
    }

    public function test_the_saved_photo_is_used_when_it_is_a_plain_web_address(): void
    {
        $this->assertSame('https://rafflekings.com.ng/storage/avatars/5.jpg?v=1', $this->customer('Ada', 'https://rafflekings.com.ng/storage/avatars/5.jpg?v=1')->profilePictureUrl());
        $this->assertSame('/storage/avatars/6.png', $this->customer('Bola', '/storage/avatars/6.png')->profilePictureUrl());
    }

    public function test_an_unsafe_or_empty_photo_value_is_ignored(): void
    {
        $this->assertNull($this->customer('Cee', 'javascript:alert(1)')->profilePictureUrl());
        $this->assertNull($this->customer('Dee', '   ')->profilePictureUrl());
        $this->assertNull($this->customer('Eve')->profilePictureUrl());
    }

    public function test_a_customer_without_a_photo_gets_their_initials(): void
    {
        $url = $this->customer('Sam Mk')->avatarOrInitialsUrl();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $this->assertStringContainsString('>SM<', base64_decode(substr($url, strlen('data:image/svg+xml;base64,'))));
    }

    public function test_initials_are_safe_even_for_odd_names(): void
    {
        $svg = base64_decode(substr($this->customer('<script>x</script>')->initialsAvatarUrl(), strlen('data:image/svg+xml;base64,')));

        $this->assertStringNotContainsString('<script>', $svg);
    }

    public function test_the_customer_list_shows_each_customers_picture(): void
    {
        $this->actingAsAdministrator();
        $this->customer('Photo Person', 'https://rafflekings.com.ng/storage/avatars/9.jpg');
        $this->customer('No Photo');

        Livewire::test(ListWpUsers::class)
            ->assertSuccessful()
            ->assertSeeHtml('https://rafflekings.com.ng/storage/avatars/9.jpg')
            ->assertSeeHtml('data:image/svg+xml;base64,');
    }

    public function test_the_customer_profile_shows_their_picture_beside_their_name(): void
    {
        $this->actingAsAdministrator();
        $user = $this->customer('Sammmk', 'https://rafflekings.com.ng/storage/avatars/168.jpg?v=3');

        Livewire::test(ViewWpUser::class, ['record' => $user->getKey()])
            ->assertSuccessful()
            ->assertSeeHtml('https://rafflekings.com.ng/storage/avatars/168.jpg?v=3')
            ->assertSee('Sammmk');
    }

    public function test_the_customer_list_does_not_make_a_query_per_customer_for_pictures(): void
    {
        $this->actingAsAdministrator();
        foreach (range(1, 12) as $i) {
            $this->customer("Person {$i}", "https://rafflekings.com.ng/storage/avatars/{$i}.jpg");
        }

        \DB::enableQueryLog();
        Livewire::test(ListWpUsers::class)->assertSuccessful();
        $photoQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'profile_pic_url'))->count();
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $photoQueries, 'pictures should be loaded in one go, not once per customer');
    }
}

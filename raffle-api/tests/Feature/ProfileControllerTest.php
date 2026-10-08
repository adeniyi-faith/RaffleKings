<?php

namespace Tests\Feature;

use App\Auth\WordPressAuthCookieValidator;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/** "Edit Personal Details" (item 26 follow-up) — rebuild of edit-profile.php. */
class ProfileControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    public function test_it_returns_the_authenticated_users_details(): void
    {
        $user = $this->actingAsWordPressUser(['display_name' => 'Jane Doe', 'user_email' => 'jane@example.com']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'phone', 'meta_value' => '08012345678']);
        WpUserMeta::create(['user_id' => $user->ID, 'meta_key' => 'state', 'meta_value' => 'Lagos']);

        $response = $this->getJson('/api/profile');

        $response->assertOk()->assertJson([
            'display_name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '08012345678',
            'state' => 'Lagos',
        ]);
    }

    public function test_a_user_can_update_their_details(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);

        $response = $this->postJson('/api/profile', [
            'current_password' => 'current-pass1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'display_name' => 'Jane D.',
            'email' => 'jane.updated@example.com',
            'phone' => '08099999999',
            'state' => 'Rivers',
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertSame('Jane D.', $user->display_name);
        $this->assertSame('jane.updated@example.com', $user->user_email);
        $this->assertSame('08099999999', $user->metaValue('phone'));
        $this->assertSame('Rivers', $user->metaValue('state'));
    }

    public function test_a_password_change_actually_verifies_against_the_shared_hasher(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'current_password' => 'current-pass1',
            'password' => 'a-brand-new-password1',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(app(WordPressPasswordHasher::class)->check('a-brand-new-password1', $user->user_pass));
    }

    public function test_changing_the_password_needs_the_current_password(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);
        $before = $user->user_pass;

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'password' => 'a-brand-new-password1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'current_password' => 'not-my-password1',
            'password' => 'a-brand-new-password1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame($before, $user->fresh()->user_pass);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);
        $email = $user->user_email;

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => 'attacker@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertSame($email, $user->fresh()->user_email);
    }

    public function test_other_details_can_be_saved_without_the_current_password(): void
    {
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/profile', [
            'display_name' => 'New Name',
            'email' => strtoupper($user->user_email),
        ])->assertOk();

        $this->assertSame('New Name', $user->fresh()->display_name);
    }

    public function test_after_a_password_change_this_device_stays_signed_in_and_others_are_signed_out(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);
        $user->createToken('another-phone');

        $response = $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'current_password' => 'current-pass1',
            'password' => 'a-brand-new-password1',
        ])->assertOk();

        $cookieName = 'wordpress_logged_in_'.config('legacy.wp_cookiehash');
        $fresh = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName);
        $this->assertNotNull($fresh, 'A fresh sign-in cookie is sent back.');

        // The new cookie works, every app token is gone.
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame($user->ID, (new WordPressAuthCookieValidator(config('legacy.wp_logged_in_key'), config('legacy.wp_logged_in_salt')))->resolve($fresh->getValue())?->ID);
    }

    public function test_a_weak_new_password_is_refused(): void
    {
        $user = $this->actingAsWordPressUser(['user_pass' => app(WordPressPasswordHasher::class)->make('current-pass1')]);

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'current_password' => 'current-pass1',
            'password' => 'onlyletters',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_email_must_be_unique(): void
    {
        WpUser::create([
            'user_login' => 'taken',
            'user_pass' => 'irrelevant',
            'user_email' => 'taken@example.com',
            'display_name' => 'Taken',
        ]);
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => 'taken@example.com',
        ])->assertStatus(422);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }

    public function test_a_user_can_upload_an_avatar(): void
    {
        Storage::fake('public');
        $user = $this->actingAsWordPressUser();

        $response = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ]);

        $response->assertOk();
        Storage::disk('public')->assertExists('avatars/'.$user->ID.'.webp');
        $this->assertSame($response->json('avatar'), $user->fresh()->metaValue('profile_pic_url'));
    }

    public function test_uploading_a_new_avatar_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('first.jpg')])->assertOk();
        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('second.png')])->assertOk();

        $this->assertSame(['avatars/'.$user->ID.'.webp'], Storage::disk('public')->files('avatars'));
    }

    public function test_a_big_photo_is_saved_as_a_small_square_webp(): void
    {
        Storage::fake('public');
        $user = $this->actingAsWordPressUser();
        $photo = UploadedFile::fake()->image('big.jpg', 3000, 2000);

        $this->postJson('/api/profile/avatar', ['avatar' => $photo])->assertOk();

        $saved = Storage::disk('public')->get('avatars/'.$user->ID.'.webp');
        $info = getimagesizefromstring($saved);
        $this->assertSame([320, 320, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->assertLessThan($photo->getSize(), strlen($saved));
    }

    public function test_an_animated_gif_is_kept_as_it_is(): void
    {
        Storage::fake('public');
        $user = $this->actingAsWordPressUser();
        // Two frames: shrinking it would freeze the animation.
        $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAIfkEAAAAAAAsAAAAAAEAAQAAAgJEAQA7');
        $file = UploadedFile::fake()->createWithContent('moving.gif', $gif);

        $this->postJson('/api/profile/avatar', ['avatar' => $file])->assertOk();

        $this->assertSame($gif, Storage::disk('public')->get('avatars/'.$user->ID.'.gif'));
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $this->actingAsWordPressUser();

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('resume.pdf', 100),
        ])->assertStatus(422);
    }
}

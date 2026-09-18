<?php

namespace Tests\Feature;

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
        $user = $this->actingAsWordPressUser();

        $response = $this->postJson('/api/profile', [
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
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/profile', [
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'password' => 'a-brand-new-password',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(app(WordPressPasswordHasher::class)->check('a-brand-new-password', $user->user_pass));
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
        Storage::disk('public')->assertExists('avatars/'.$user->ID.'.jpg');
        $this->assertSame($response->json('avatar'), $user->fresh()->metaValue('profile_pic_url'));
    }

    public function test_uploading_a_new_avatar_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $user = $this->actingAsWordPressUser();

        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('first.jpg')])->assertOk();
        $this->postJson('/api/profile/avatar', ['avatar' => UploadedFile::fake()->image('second.png')])->assertOk();

        Storage::disk('public')->assertMissing('avatars/'.$user->ID.'.jpg');
        Storage::disk('public')->assertExists('avatars/'.$user->ID.'.png');
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

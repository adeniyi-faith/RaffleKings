<?php

namespace Tests\Feature;

use App\Models\Legacy\RaffleWinner;
use App\Models\Legacy\WpUserMeta;
use App\Models\WinnerStory;
use App\Services\Engagement\WinnerStories;
use App\Services\Images\ImageOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\CreatesRaffles;
use Tests\TestCase;

/** Customer photos are shrunk on upload, and old ones by `images:shrink`, without losing the originals. */
class ImageShrinkingTest extends TestCase
{
    use ActsAsAdministrator, CreatesRaffles, RefreshDatabase;

    public function test_a_sideways_phone_photo_is_turned_upright(): void
    {
        // 400 wide x 200 tall, with the camera's note "turn me 90 degrees".
        $jpeg = $this->jpegWithOrientation($this->jpeg(400, 200), 6);
        $optimizer = new ImageOptimizer;

        $this->assertSame(6, $optimizer->jpegOrientation($jpeg));

        $info = getimagesizefromstring($optimizer->fit($jpeg));
        $this->assertSame([200, 400], [$info[0], $info[1]]);
    }

    public function test_a_winner_story_photo_is_shrunk_to_1600_pixels_on_its_longest_side(): void
    {
        Storage::fake('public');
        $this->createRaffle(['public_id' => 5]);
        $user = $this->actingAsAdministrator();
        $win = RaffleWinner::create(['raffle_id' => 5, 'user_id' => $user->ID, 'ticket_number' => 7, 'prize_name' => 'Cash', 'prize_rank' => 1, 'prize_cash_value' => 0, 'is_visible' => true]);

        $story = app(WinnerStories::class)->post($user, $win->id, null, UploadedFile::fake()->image('prize.jpg', 4000, 3000));

        $this->assertStringEndsWith('.webp', $story->media_path);
        $info = getimagesizefromstring(Storage::disk('public')->get($story->media_path));
        $this->assertSame([1600, 1200], [$info[0], $info[1]]);
    }

    public function test_the_shrink_command_previews_then_shrinks_old_pictures_keeping_the_originals(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $original = $this->jpeg(1200, 900);
        Storage::disk('public')->put('avatars/7.jpg', $original);
        WpUserMeta::create(['user_id' => 7, 'meta_key' => 'profile_pic_url', 'meta_value' => 'https://rafflekings.com.ng/storage/avatars/7.jpg?v=1']);

        // A photo still on the old WordPress site is downloaded, not touched there.
        Http::fake(['old.example/*' => Http::response($this->jpeg(800, 800), 200, ['Content-Type' => 'image/jpeg'])]);
        WpUserMeta::create(['user_id' => 8, 'meta_key' => 'profile_pic_url', 'meta_value' => 'https://old.example/wp-content/uploads/me.jpg']);

        $this->artisan('images:shrink --dry-run')->assertSuccessful();
        Storage::disk('public')->assertMissing('avatars/7.webp');
        $this->assertSame('https://rafflekings.com.ng/storage/avatars/7.jpg?v=1', WpUserMeta::query()->where('user_id', 7)->where('meta_key', 'profile_pic_url')->value('meta_value'));

        $this->artisan('images:shrink')->assertSuccessful();

        foreach ([7, 8] as $id) {
            Storage::disk('public')->assertExists("avatars/{$id}.webp");
            $this->assertMatchesRegularExpression('#/storage/avatars/'.$id.'\.webp\?v=\d+$#', WpUserMeta::query()->where('user_id', $id)->where('meta_key', 'profile_pic_url')->value('meta_value'));
        }

        $this->assertSame($original, Storage::disk('public')->get('avatars/7.jpg'));
        $this->assertSame('https://old.example/wp-content/uploads/me.jpg', WpUserMeta::query()->where('user_id', 8)->where('meta_key', 'profile_pic_url_before_shrink')->value('meta_value'));
        $this->assertCount(1, Storage::disk('local')->files('image-shrink-logs'));

        // Running it again finds nothing left to do.
        $this->artisan('images:shrink')->expectsOutputToContain('0 picture(s) shrunk')->assertSuccessful();
    }

    public function test_the_shrink_command_shrinks_old_story_photos(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('stories/old.jpg', $this->jpeg(3000, 2000));
        $story = WinnerStory::create(['user_id' => 1, 'raffle_winner_id' => 1, 'raffle_id' => 1, 'media_path' => 'stories/old.jpg', 'media_type' => 'image', 'status' => 'approved']);

        $this->artisan('images:shrink')->assertSuccessful();

        $this->assertStringEndsWith('.webp', $story->fresh()->media_path);
        Storage::disk('public')->assertExists('stories/old.jpg');
    }

    /** A real photo-like JPEG (random-ish pixels so it isn't trivially tiny). */
    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y += 8) {
            for ($x = 0; $x < $w; $x += 8) {
                imagefilledrectangle($img, $x, $y, $x + 7, $y + 7, imagecolorallocate($img, ($x * 7 + $y) % 256, ($y * 3) % 256, mt_rand(0, 255)));
            }
        }

        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }

    /** Adds an EXIF block holding just the orientation tag right after the JPEG's start marker. */
    private function jpegWithOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation).pack('n', 0)
            .pack('N', 0);
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}

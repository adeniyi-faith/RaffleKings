<?php

namespace Tests\Feature;

use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ActsAsAdministrator;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/** Customers can attach screenshots to support tickets; they stay private. */
class SupportScreenshotsTest extends TestCase
{
    use ActsAsAdministrator, AuthenticatesWithWordPressCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        config(['ai.enabled' => false]);
    }

    public function test_a_customer_can_open_a_ticket_with_a_screenshot_and_see_it(): void
    {
        $this->actingAsWordPressUser();

        $response = $this->post('/api/support/tickets', [
            'subject' => 'Payment issue',
            'message' => 'My transfer is not showing.',
            'screenshots' => [UploadedFile::fake()->image('receipt.png', 400, 800)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $url = $response->json('messages.0.attachment_urls.0');
        $this->assertNotNull($url);
        $this->assertArrayNotHasKey('attachments', $response->json('messages.0'));

        $path = SupportTicketMessage::firstOrFail()->attachments[0];
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('support-attachments/', $path);

        $this->get($url)->assertOk();
    }

    public function test_a_reply_can_be_just_a_screenshot(): void
    {
        $user = $this->actingAsWordPressUser();
        $ticket = SupportTicket::create(['user_id' => $user->ID, 'subject' => 'x', 'status' => 'pending']);

        $this->post("/api/support/tickets/{$ticket->id}/reply", [
            'screenshots' => [UploadedFile::fake()->image('error.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertCount(1, $ticket->messages()->firstOrFail()->attachments);
        $this->postJson("/api/support/tickets/{$ticket->id}/reply", [])->assertStatus(422);
    }

    public function test_only_small_pictures_are_accepted(): void
    {
        $this->actingAsWordPressUser();

        foreach ([
            [UploadedFile::fake()->create('virus.svg', 10, 'image/svg+xml')],
            [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            [UploadedFile::fake()->image('huge.png')->size(6000)],
            array_fill(0, 4, UploadedFile::fake()->image('a.png')),
        ] as $files) {
            $this->post('/api/support/tickets', ['subject' => 'x', 'message' => 'y', 'screenshots' => $files], ['Accept' => 'application/json'])
                ->assertStatus(422);
        }

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_a_screenshot_link_cannot_be_guessed_or_changed(): void
    {
        $this->actingAsWordPressUser();
        $this->post('/api/support/tickets', [
            'subject' => 'x', 'message' => 'y', 'screenshots' => [UploadedFile::fake()->image('a.png')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $message = SupportTicketMessage::firstOrFail();
        $this->get("/support/attachments/{$message->id}/0")->assertForbidden();
        $this->get(str_replace('/0?', '/1?', $message->attachment_urls[0]))->assertForbidden();

        // Another customer can't read the ticket that holds the link.
        $this->actingAsWordPressUser();
        $this->getJson("/api/support/tickets/{$message->support_ticket_id}")->assertNotFound();
    }

    public function test_staff_see_the_screenshot_on_the_ticket(): void
    {
        $admin = $this->actingAsAdministrator();
        $ticket = SupportTicket::create(['user_id' => $admin->ID, 'subject' => 'Hi', 'status' => 'open']);
        $path = UploadedFile::fake()->image('a.png')->store('support-attachments/'.$ticket->id, 'local');
        SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'author_id' => $admin->ID, 'is_from_admin' => false, 'message' => 'See picture', 'attachments' => [$path], 'created_at' => now()]);

        Livewire::test(ViewSupportTicket::class, ['record' => $ticket->getKey()])
            ->assertSee('Screenshot 1');
    }

    public function test_the_ai_is_shown_the_screenshot(): void
    {
        config(['ai.enabled' => true, 'ai.auto_reply' => true, 'ai.daily_limit' => 50, 'services.gemini.api_key' => 'k']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['answerable' => false, 'solved' => false, 'reply' => '', 'reason' => 'x'])]]]]],
        ])]);

        $this->actingAsWordPressUser();
        $this->post('/api/support/tickets', [
            'subject' => 'x', 'message' => 'What is this error?', 'screenshots' => [UploadedFile::fake()->image('err.png')],
        ], ['Accept' => 'application/json'])->assertCreated();

        Http::assertSent(fn ($r) => ($r['contents'][0]['parts'][1]['inline_data']['mime_type'] ?? null) === 'image/png');
    }
}

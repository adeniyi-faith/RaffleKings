<?php

namespace Tests\Feature;

use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\AuthenticatesWithWordPressCookie;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the "upload a statement, get
 * a list of credits" step AuditReconciliationService's own docblock
 * deliberately left unbuilt in the previous item-36 pass.
 */
class StatementExtractionControllerTest extends TestCase
{
    use AuthenticatesWithWordPressCookie, RefreshDatabase;

    private function actingAsAdministrator(): WpUser
    {
        $admin = $this->actingAsWordPressUser();

        WpUserMeta::create([
            'user_id' => $admin->ID,
            'meta_key' => config('legacy.wp_prefix').'capabilities',
            'meta_value' => serialize(['administrator' => true]),
        ]);

        return $admin;
    }

    public function test_a_non_administrator_cannot_reach_the_endpoint(): void
    {
        $this->actingAsWordPressUser();

        $this->postJson('/api/admin/audit/extract-statement', [
            'files' => [UploadedFile::fake()->image('statement.png')],
        ])->assertStatus(403);
    }

    public function test_extraction_returns_the_credits_the_model_reports(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => '```json'."\n".'[{"amount": 5000, "date": "2026-01-15", "desc": "Transfer from John"}, {"amount": 1200.5, "date": null, "desc": null}]'."\n".'```',
                    ]]],
                ]],
            ]),
        ]);
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/admin/audit/extract-statement', [
            'files' => [UploadedFile::fake()->image('statement.png')],
        ]);

        $response->assertOk();
        $response->assertJson(['credits' => [
            ['amount' => 5000.0, 'date' => '2026-01-15', 'desc' => 'Transfer from John'],
            ['amount' => 1200.5, 'date' => null, 'desc' => null],
        ]]);
    }

    public function test_a_missing_gemini_key_returns_a_clear_422_not_a_silent_empty_list(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/admin/audit/extract-statement', [
            'files' => [UploadedFile::fake()->image('statement.png')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'No Gemini API key configured — set GEMINI_API_KEY to enable statement extraction.']);
    }

    public function test_a_non_statement_file_type_is_rejected_by_validation(): void
    {
        $this->actingAsAdministrator();

        $this->postJson('/api/admin/audit/extract-statement', [
            'files' => [UploadedFile::fake()->create('statement.exe', 100)],
        ])->assertStatus(422);
    }

    public function test_an_unparseable_model_response_raises_a_clear_error(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'Sorry, I cannot read this image.']]],
                ]],
            ]),
        ]);
        $this->actingAsAdministrator();

        $response = $this->postJson('/api/admin/audit/extract-statement', [
            'files' => [UploadedFile::fake()->image('statement.png')],
        ]);

        $response->assertStatus(422);
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Services\Monitoring\HealthReport;
use App\Support\Formats;
use App\Support\TextWidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

/**
 * The live host runs PHP without the "mbstring" and "intl" add-ons, which
 * crashed most admin lists (Str::limit needs mb_strimwidth; Filament's
 * ->money()/->numeric() and pagination need intl). These keep the
 * replacements honest and stop the intl-only calls creeping back in.
 */
class BareHostingTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    public function test_the_text_trimming_fallback_matches_php(): void
    {
        foreach ([
            ['Hello world', 0, 5, '...'],
            ['Hello world', 0, 50, '...'],
            ['Hello world', 6, 3, ''],
            ['Olúwasẹ́un Adébáyọ̀ paid ₦4,000', 0, 12, '…'],
            ['日本語のテキスト', 0, 7, '..'],
            ['', 0, 5, '...'],
        ] as [$text, $start, $width, $marker]) {
            $this->assertSame(mb_strimwidth($text, $start, $width, $marker), TextWidth::trim($text, $start, $width, $marker), $text);
        }
    }

    public function test_money_and_numbers_are_formatted_without_intl(): void
    {
        $this->assertSame('₦4,000', Formats::naira(4000));
        $this->assertSame('₦1,250.50', Formats::naira('1250.5'));
        $this->assertSame('-₦500', Formats::naira(-500));
        $this->assertSame('₦0', Formats::naira(null));
        $this->assertSame('12,345', Formats::wholeNumber(12345));
    }

    public function test_admin_code_does_not_use_intl_only_formatting(): void
    {
        $finder = (new Finder)->files()->in([app_path(), resource_path('views')])->name(['*.php']);

        foreach ($finder as $file) {
            // Code only: comments may name the calls they avoid.
            $code = collect(token_get_all(preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents())))
                ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($t) => is_array($t) ? $t[1] : $t)
                ->implode('');
            $this->assertDoesNotMatchRegularExpression('/->money\(|Number::(format|currency|percentage|abbreviate|forHumans)\(/', $code, $file->getRelativePathname().' needs intl; use ->naira()/->wholeNumber() or App\Support\Formats.');
        }
    }

    public function test_the_health_page_reports_php_add_ons(): void
    {
        $names = array_column(app(HealthReport::class)->run(), 1);

        $this->assertContains('PHP extensions', $names);
    }

    public function test_phone_menu_has_no_duplicate_top_button_and_scrolls(): void
    {
        $this->actingAsAdministrator();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('.fi-topbar-open-sidebar-btn { display: none !important; }', false)
            ->assertSee('overscroll-behavior: contain', false);
    }
}

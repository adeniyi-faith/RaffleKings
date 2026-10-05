<?php

namespace App\Filament\Support;

use App\Filament\Pages;
use App\Filament\Resources;
use App\Settings\Setting;
use App\Settings\SettingsRegistry;
use Filament\Facades\Filament;
use Filament\GlobalSearch\Contracts\GlobalSearchProvider;
use Filament\GlobalSearch\DefaultGlobalSearchProvider;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Support\Str;
use Throwable;

/**
 * The search box at the top of the admin (also Ctrl/⌘+K). Finds, in order:
 *
 *  1. Things to DO ("pay a withdrawal", "maintenance", "ban a customer"),
 *     each taking you to the screen where it's done;
 *  2. Admin screens by name or by what they're for;
 *  3. Individual settings, opening Settings on the right tab;
 *  4. Records: customers, raffles, support tickets, promo codes, affiliates.
 *
 * Only what the signed-in staff member's role can open is ever shown.
 * Every word typed must match (in any order), so "paystack key" finds
 * the Paystack secret key but not every setting that mentions Paystack.
 */
class AdminSearch implements GlobalSearchProvider
{
    private const PER_GROUP = 8;

    /**
     * Things staff do, and extra words people might type for them.
     * [what, screen class, screen page ('index', 'create', or a Settings tab), extra words]
     *
     * @var list<array{0: string, 1: class-string, 2: string, 3: string}>
     */
    private const ACTIONS = [
        ['Pay a withdrawal (mark paid)', Resources\WithdrawalRequestResource::class, 'index', 'payout cash out approve withdraw transfer money send'],
        ['Send a withdrawal with Paystack', Resources\WithdrawalRequestResource::class, 'index', 'automatic payout transfer bulk'],
        ['Reject and refund a withdrawal', Resources\WithdrawalRequestResource::class, 'index', 'decline refund withdraw'],
        ['Approve a bank-transfer top-up', Resources\BankTransferResource::class, 'index', 'deposit receipt proof screenshot credit wallet fund'],
        ['Fix a top-up paid with the wrong amount', Resources\PaymentMismatchResource::class, 'index', 'mismatch deposit amount paystack flutterwave'],
        ['Look up a payment', Resources\PaymentResource::class, 'index', 'deposit topup paystack flutterwave reference recheck'],
        ['Create a raffle', Resources\RaffleResource::class, 'create', 'new raffle add prize ticket'],
        ['Run a draw / pick winners', Resources\RaffleDrawResource::class, 'index', 'winner draw lock seed provably fair live draw simulate'],
        ['Pay or deliver a prize', Resources\RaffleWinnerResource::class, 'index', 'winner prize credit payout'],
        ['Look up a ticket number', Resources\TicketResource::class, 'index', 'ticket entry number owner'],
        ['Ban, restrict or adjust a customer\'s balance', Resources\Legacy\WpUserResource::class, 'index', 'customer user ban suspend block restrict balance adjust credit debit wallet points'],
        ['See the team to-do list (the bell)', Pages\TeamTodo::class, 'index', 'to-do todo task bell notification alert waiting attention checklist done'],
        ['Check suspicious customers', Pages\FraudWatch::class, 'index', 'fraud abuse multiple accounts scam held rewards shared bank'],
        ['Reply to a support ticket', Resources\SupportTicketResource::class, 'index', 'help complaint customer message support'],
        ['Moderate live-draw chat', Resources\LiveChatResource::class, 'index', 'chat hide comment mute moderation red envelope'],
        ['Message customers (push, email, inbox)', Resources\BroadcastResource::class, 'create', 'broadcast notification push email announcement send all'],
        ['Post an announcement on the site', Resources\SiteNoticeResource::class, 'create', 'banner notice alert popup toast announcement'],
        ['Create a promo code', Resources\PromoCodeResource::class, 'create', 'coupon discount voucher code campaign influencer'],
        ['Add an affiliate / influencer', Resources\AffiliateResource::class, 'create', 'influencer partner link commission'],
        ['Edit the home page', Resources\HomeSectionResource::class, 'index', 'homepage layout sections banner'],
        ['Edit the Terms or About page', Resources\SitePageResource::class, 'index', 'terms conditions about page legal'],
        ['Add a daily prediction', Resources\PredictionResource::class, 'create', 'prediction quiz football question'],
        ['Approve a winner story', Resources\WinnerStoryResource::class, 'index', 'story testimonial photo video'],
        ['Add a staff member or change a role', Resources\StaffResource::class, 'index', 'staff admin team role permission access'],
        ['See who signed in and what staff changed', Pages\StaffActivity::class, 'index', 'staff activity sign in login failed password guess two-step 2fa code security team'],
        ['Settings history: see what changed and undo it', Resources\SettingChangeResource::class, 'index', 'settings history undo revert changed price key mistake put back'],
        ['See who changed what (audit log)', Resources\AdminAuditLogResource::class, 'index', 'audit log history who changed'],
        ['Money reports', Pages\MoneyReports::class, 'index', 'money report profit loss income kept revenue top-ups withdrawals prizes refunds by day week month raffle'],
        ['Download reports', Pages\Downloads::class, 'index', 'export csv excel report download statement'],
        ['Check today\'s bank statement', Pages\DailyAudit::class, 'index', 'audit statement reconcile bank'],
        ['Check balances add up', Pages\FinancialReconciliation::class, 'index', 'reconcile ledger balance drift'],
        ['See sales and growth', Pages\BusinessInsights::class, 'index', 'insights analytics revenue sales profit chart'],
        ['Check the site is healthy (backups, cron, errors)', Pages\SystemHealth::class, 'index', 'health backup restore cron queue error status uptime'],
        ['Turn maintenance mode on or off', Pages\Settings::class, 'On / off', 'maintenance offline down back soon'],
        ['Pause ticket sales, top-ups or withdrawals', Pages\Settings::class, 'On / off', 'pause stop disable switch off'],
        ['Switch new features on or off', Pages\Settings::class, 'On / off', 'feature toggle enable disable promo affiliate reminders payouts'],
        ['Change ticket prices and discounts', Pages\Settings::class, 'Raffles & pricing', 'price discount bulk bundle golden box'],
        ['Change payment keys (Paystack, Flutterwave)', Pages\Settings::class, 'Payments', 'paystack flutterwave key gateway'],
        ['Set up backups and the status message', Pages\Settings::class, 'Backups & status', 'backup restore status uptime heartbeat'],
    ];

    public function getResults(string $query): ?GlobalSearchResults
    {
        $words = $this->words($query);

        if ($words === []) {
            return null;
        }

        $results = GlobalSearchResults::make();

        if ($found = $this->actions($words)) {
            $results->category('Do something', $found);
        }

        if ($found = $this->screens($words)) {
            $results->category('Admin pages', $found);
        }

        if ($found = $this->settings($words)) {
            $results->category('Settings', $found);
        }

        // Records (customers, raffles, tickets…) from each screen's own search.
        foreach ((new DefaultGlobalSearchProvider)->getResults($query)?->getCategories() ?? [] as $name => $records) {
            $results->category(ucfirst($name), $records);
        }

        return $results;
    }

    /** @return list<GlobalSearchResult> */
    private function actions(array $words): array
    {
        $found = [];

        foreach (self::ACTIONS as [$label, $screen, $page, $keywords]) {
            $score = $this->score($words, $label, $keywords.' '.$page);

            if ($score > 0 && ($url = $this->url($screen, $page))) {
                $found[] = [$score, new GlobalSearchResult($label, $url, ['Where' => $this->screenName($screen, $page)])];
            }
        }

        return $this->best($found);
    }

    /** Every admin screen in the menu that this person can open. @return list<GlobalSearchResult> */
    private function screens(array $words): array
    {
        $found = [];

        foreach ($this->openableScreens() as [$label, $group, $url, $extra]) {
            $score = $this->score($words, $label, $group.' '.$extra);

            if ($score > 0) {
                $found[] = [$score, new GlobalSearchResult($label, $url, array_filter(['Menu' => $group]))];
            }
        }

        return $this->best($found);
    }

    /** Each setting, opening Settings on its tab. @return list<GlobalSearchResult> */
    private function settings(array $words): array
    {
        if (! Pages\Settings::canAccess()) {
            return [];
        }

        $found = [];

        foreach (SettingsRegistry::tabs() as $tab => $definition) {
            foreach ($definition['sections'] as $section => $contents) {
                $sectionScore = $this->score($words, $section, $tab);

                if ($sectionScore > 0) {
                    $found[] = [$sectionScore + 1, new GlobalSearchResult($section, $this->settingsUrl($tab), ['Settings' => $tab])];
                }

                /** @var Setting $setting */
                foreach ($contents['settings'] as $setting) {
                    $score = $this->score($words, $setting->label, "{$tab} {$section} ".strip_tags((string) $setting->help));

                    if ($score > 0) {
                        $found[] = [$score, new GlobalSearchResult(
                            $setting->label,
                            $this->settingsUrl($tab, $setting),
                            ['Settings' => "{$tab} → {$section}"],
                        )];
                    }
                }
            }
        }

        return $this->best($found);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> [label, menu group, url, extra words] */
    private function openableScreens(): array
    {
        $screens = [];

        foreach (Filament::getResources() as $resource) {
            try {
                if ($resource::canViewAny() && $resource::shouldRegisterNavigation()) {
                    $screens[] = [$resource::getNavigationLabel(), (string) $resource::getNavigationGroup(), $resource::getUrl('index'), $resource::getPluralModelLabel()];
                }
            } catch (Throwable) {
            }
        }

        foreach (Filament::getPages() as $page) {
            try {
                if ($page::canAccess() && $page::shouldRegisterNavigation()) {
                    $screens[] = [$page::getNavigationLabel(), (string) $page::getNavigationGroup(), $page::getUrl(), ''];
                }
            } catch (Throwable) {
            }
        }

        return $screens;
    }

    /** Where an action lives, or null if this person can't open it. */
    private function url(string $screen, string $page): ?string
    {
        try {
            if (is_subclass_of($screen, \Filament\Resources\Resource::class)) {
                if (! $screen::canViewAny()) {
                    return null;
                }

                return array_key_exists($page, $screen::getPages()) ? $screen::getUrl($page) : $screen::getUrl('index');
            }

            if (! $screen::canAccess()) {
                return null;
            }

            return $screen === Pages\Settings::class && $page !== 'index' ? $this->settingsUrl($page) : $screen::getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private function screenName(string $screen, string $page): string
    {
        $name = $screen::getNavigationLabel();

        return $screen === Pages\Settings::class && $page !== 'index' ? "Settings → {$page}" : trim(($screen::getNavigationGroup() ? $screen::getNavigationGroup().' → ' : '').$name);
    }

    /** Settings, opened on this tab (the page remembers its tab in the address). */
    public static function settingsUrl(string $tab, ?Setting $setting = null): string
    {
        return Pages\Settings::getUrl(['tab' => 'settings-'.Str::slug(Str::transliterate($tab, strict: true)).'-tab'])
            .($setting ? '#data.'.Pages\Settings::field($setting->key) : '');
    }

    /**
     * 0 = not a match. Every word must appear in the title or the extra
     * words; matches in the title, and at its start, rank higher.
     */
    private function score(array $words, string $title, string $extra): int
    {
        $title = Str::lower(Str::ascii($title));
        $haystack = $title.' '.Str::lower(Str::ascii($extra));
        $score = 0;

        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return 0;
            }

            $score += str_contains($title, $word) ? 3 : 1;
        }

        return $score + (str_starts_with($title, $words[0]) ? 2 : 0);
    }

    /** @param list<array{0: int, 1: GlobalSearchResult}> $found */
    private function best(array $found): array
    {
        usort($found, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(fn ($row) => $row[1], array_slice($found, 0, self::PER_GROUP));
    }

    /** @return list<string> */
    private function words(string $query): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', Str::lower(Str::ascii(trim($query)))) ?: [],
            fn ($w) => mb_strlen($w) >= 2,
        ));
    }
}

<?php

namespace App\Auth;

use App\Filament;

/**
 * Staff roles for the admin (Users → Staff, item 45b). Every WordPress
 * administrator is an Owner unless given another role, so nothing changes
 * until an owner hands out roles. A role is stored in the `rk_staff_role`
 * usermeta; "none" removes admin access altogether.
 *
 * AREAS is the one place that says which role can open which screen;
 * buttons that move money or change customers check their own ability
 * (e.g. money.pay) on top.
 */
final class StaffRoles
{
    public const META_KEY = 'rk_staff_role';

    public const NO_ACCESS = 'none';

    public const ROLES = [
        'owner' => [
            'label' => 'Owner',
            'description' => 'Everything, including Settings and staff roles.',
            'abilities' => ['*'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Everything except Settings and staff roles.',
            'abilities' => ['money.view', 'money.pay', 'customers.view', 'customers.manage', 'support', 'chat', 'raffles', 'content', 'messages', 'reports', 'system'],
        ],
        'finance' => [
            'label' => 'Finance',
            'description' => 'Payouts, bank transfers, payments, transactions, winners, customers and downloads.',
            'abilities' => ['money.view', 'money.pay', 'customers.view', 'customers.manage', 'reports'],
        ],
        'support' => [
            'label' => 'Support',
            'description' => 'Support tickets, customers (look only), ticket lookup, re-checking payments and chat. Can\'t pay out or change balances.',
            'abilities' => ['support', 'customers.view', 'payments.view', 'payments.recheck', 'chat'],
        ],
        'content' => [
            'label' => 'Content',
            'description' => 'Raffles and draws, announcements, tutorials, the Terms and About pages, chat and messages to customers.',
            'abilities' => ['raffles', 'content', 'chat', 'messages'],
        ],
    ];

    /** Which ability opens which admin screen (dashboard: every staff member). */
    public const AREAS = [
        Filament\Resources\WithdrawalRequestResource::class => 'money.view',
        Filament\Resources\BankTransferResource::class => 'money.view',
        Filament\Resources\PaymentMismatchResource::class => 'money.view',
        Filament\Resources\TransactionResource::class => 'money.view',
        Filament\Resources\RaffleWinnerResource::class => 'money.view',
        Filament\Resources\ReferralCommissionResource::class => 'money.view',
        Filament\Resources\UserPointsResource::class => 'money.view',
        Filament\Pages\DailyAudit::class => 'money.pay',
        Filament\Pages\FinancialReconciliation::class => 'money.pay',
        Filament\Resources\PaymentResource::class => ['money.view', 'payments.view'],
        Filament\Pages\Downloads::class => 'reports',
        Filament\Pages\BusinessInsights::class => 'reports',
        Filament\Resources\Legacy\WpUserResource::class => 'customers.view',
        Filament\Resources\TicketResource::class => 'customers.view',
        Filament\Pages\FraudWatch::class => 'customers.view',
        Filament\Resources\SupportTicketResource::class => 'support',
        Filament\Resources\KnowledgeArticleResource::class => ['support', 'content'],
        Filament\Resources\LiveChatResource::class => 'chat',
        Filament\Resources\RaffleResource::class => 'raffles',
        Filament\Resources\RaffleDrawResource::class => 'raffles',
        Filament\Resources\PredictionResource::class => 'content',
        Filament\Resources\WinnerStoryResource::class => 'content',
        Filament\Resources\SiteNoticeResource::class => 'content',
        Filament\Resources\TutorialResource::class => 'content',
        Filament\Resources\SitePageResource::class => 'content',
        Filament\Resources\HomeSectionResource::class => 'content',
        Filament\Resources\BroadcastResource::class => 'messages',
        Filament\Resources\AdminAuditLogResource::class => 'system',
        Filament\Pages\SystemHealth::class => 'system',
        Filament\Pages\Settings::class => 'settings',
        Filament\Resources\StaffResource::class => 'staff',
    ];

    /** @return array<string, string> role => label */
    public static function options(): array
    {
        return array_map(fn ($r) => $r['label'], self::ROLES);
    }

    public static function allows(?string $role, string $ability): bool
    {
        $abilities = self::ROLES[$role]['abilities'] ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /** Can this role open the given admin screen? Unlisted screens: owners only. */
    public static function canOpen(?string $role, string $screen): bool
    {
        $needs = (array) (self::AREAS[$screen] ?? 'owner-only');

        foreach ($needs as $ability) {
            if (self::allows($role, $ability)) {
                return true;
            }
        }

        return false;
    }
}

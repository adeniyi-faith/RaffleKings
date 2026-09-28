<?php

namespace App\Filament\Support;

use App\Filament\Resources\BankTransferResource;
use App\Filament\Resources\SupportTicketResource;
use App\Filament\Resources\WithdrawalRequestResource;
use Filament\Pages\Dashboard;
use Illuminate\Support\Str;

/**
 * The tab bar along the bottom of the admin on a phone: the dashboard and
 * the three queues staff clear every day, one thumb-tap away, each with
 * the same "how many waiting" number as the side menu. "Menu" opens the
 * full side menu for everything else.
 */
final class MobileTabs
{
    /** @return array<int, array{label: string, icon: string, url: string, badge: ?string, active: bool}> */
    public static function items(): array
    {
        $current = rtrim(request()->url(), '/');

        $tab = fn (string $label, string $icon, string $url, ?string $badge = null, bool $exact = false) => [
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'badge' => $badge,
            'active' => $exact ? $current === rtrim($url, '/') : Str::startsWith($current, rtrim($url, '/')),
        ];

        return [
            $tab('Home', 'heroicon-o-home', Dashboard::getUrl(), exact: true),
            $tab('Payouts', 'heroicon-o-arrow-up-tray', WithdrawalRequestResource::getUrl(), WithdrawalRequestResource::getNavigationBadge()),
            $tab('Transfers', 'heroicon-o-building-library', BankTransferResource::getUrl(), BankTransferResource::getNavigationBadge()),
            $tab('Support', 'heroicon-o-chat-bubble-left-right', SupportTicketResource::getUrl(), SupportTicketResource::getNavigationBadge()),
        ];
    }
}

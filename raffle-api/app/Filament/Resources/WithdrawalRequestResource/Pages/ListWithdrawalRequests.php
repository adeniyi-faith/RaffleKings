<?php

namespace App\Filament\Resources\WithdrawalRequestResource\Pages;

use App\Filament\Resources\WithdrawalRequestResource;
use App\Support\Features;
use Filament\Resources\Pages\ListRecords;

class ListWithdrawalRequests extends ListRecords
{
    protected static string $resource = WithdrawalRequestResource::class;

    /** Automatic payouts: how much Paystack has to send from. */
    public function getSubheading(): ?string
    {
        if (! Features::on('auto_payouts')) {
            return null;
        }

        $balance = WithdrawalRequestResource::paystackBalance();

        return $balance === null
            ? 'Automatic payouts are on. Paystack balance: couldn\'t be read right now.'
            : 'Automatic payouts are on. Paystack balance: '.WithdrawalRequestResource::naira($balance).'.';
    }
}

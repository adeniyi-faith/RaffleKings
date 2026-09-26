<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}

        <div class="mt-4 flex gap-3">
            <x-filament::button wire:click="extract" color="gray">
                Extract credits from upload
            </x-filament::button>

            <x-filament::button wire:click="reconcile">
                Reconcile
            </x-filament::button>
        </div>
    </form>

    @if ($flagged !== null)
        <div class="mt-8">
            <h2 class="text-lg font-semibold mb-2">Flagged transactions</h2>

            @if (empty($flagged))
                <p class="text-sm text-gray-500">Every verified transaction in this period matched a bank credit — nothing flagged.</p>
            @else
                <div class="space-y-2">
                    @foreach ($flagged as $row)
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                            <div>
                                <div class="font-medium">Txn #{{ $row['transaction']->id }} — user #{{ $row['transaction']->user_id }} — ₦{{ number_format((float) $row['transaction']->claimed_amount) }}</div>
                                <div class="text-sm text-gray-500">{{ $row['reason'] }}</div>
                            </div>
                            <x-filament::button
                                wire:click="revoke({{ $row['transaction']->id }})"
                                wire:confirm="Revoke transaction #{{ $row['transaction']->id }}? This reverses its credit and deletes any tickets it bought."
                                color="danger"
                                size="sm"
                            >
                                Revoke
                            </x-filament::button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>

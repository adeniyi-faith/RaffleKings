<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ShowComplianceValues extends Command
{
    protected $signature = 'compliance:values';

    protected $description = 'Lists the legal and policy numbers the app relies on, where each came from, and which still need confirming';

    public function handle(): int
    {
        $rows = collect(config('compliance.values'))->map(fn ($v) => [
            $v['label'],
            $v['config'] ? json_encode(config($v['config'])) : '(not in the app)',
            $v['source'],
            $v['effective_from'] ?? '-',
        ]);

        $this->table(['What', 'Value now', 'Where it came from', 'Since'], $rows);

        $open = collect(config('compliance.values'))->filter(fn ($v) => str_starts_with($v['source'], 'NEEDS CONFIRMATION'))->count();
        $open > 0 ? $this->warn("{$open} item(s) still need confirming with a lawyer or accountant.") : $this->info('Everything is confirmed.');

        return self::SUCCESS;
    }
}

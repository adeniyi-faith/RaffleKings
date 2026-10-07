<?php

namespace App\Console\Commands;

use App\Services\Compliance\CustomerRiskScore;
use Illuminate\Console\Command;

class ScoreCustomerRisk extends Command
{
    protected $signature = 'risk:score';

    protected $description = 'Works out a low / medium / high level, with reasons, for customers active in the last 90 days';

    public function handle(CustomerRiskScore $scores): int
    {
        $this->info('Scored '.$scores->refreshActive().' customer(s).');

        return self::SUCCESS;
    }
}

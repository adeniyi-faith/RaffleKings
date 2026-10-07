<?php

namespace Tests\Feature;

use App\Filament\Resources\ComplianceCaseResource\Pages\ListComplianceCases;
use App\Models\BankAccount;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseNote;
use App\Models\CustomerRiskLevel;
use App\Models\Legacy\WpUser;
use App\Services\Compliance\ComplianceCases;
use App\Services\Compliance\CustomerRiskScore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\ActsAsAdministrator;
use Tests\TestCase;

class ComplianceBasicsTest extends TestCase
{
    use ActsAsAdministrator, RefreshDatabase;

    private function person(string $name): WpUser
    {
        return WpUser::create(['user_login' => $name, 'user_pass' => 'x', 'user_email' => "{$name}@example.com", 'display_name' => $name]);
    }

    public function test_a_case_is_closed_by_a_different_person_and_notes_cannot_be_changed(): void
    {
        $opener = $this->person('opener');
        $other = $this->person('other');
        $customer = $this->person('ada');
        $cases = app(ComplianceCases::class);

        $case = $cases->open($opener, $customer, 'Odd cash-out pattern');
        $note = $cases->note($opener, $case, 'Spoke to support, waiting for a reply.');

        try {
            $cases->close($opener, $case, 'Fine.');
            $this->fail('the person who opened it must not close it');
        } catch (RuntimeException $e) {
            $this->assertSame('open', $case->fresh()->status);
        }

        $this->assertSame('closed', $cases->close($other, $case, 'Checked, nothing wrong.')->status);

        try {
            DB::table('compliance_case_notes')->where('id', $note->id)->update(['body' => 'changed']);
            $this->fail('notes must not be editable');
        } catch (QueryException $e) {
            $this->assertSame('Spoke to support, waiting for a reply.', ComplianceCaseNote::find($note->id)->body);
        }
    }

    public function test_risk_level_is_stored_with_reasons(): void
    {
        $ada = $this->person('ada');
        $twin = $this->person('twin');
        BankAccount::create(['user_id' => $ada->ID, 'bank_name' => 'GTBank', 'account_number' => '5555555555', 'account_name' => 'Someone Else', 'is_primary' => true, 'name_mismatch' => true]);
        BankAccount::create(['user_id' => $twin->ID, 'bank_name' => 'GTBank', 'account_number' => '5555555555', 'account_name' => 'Someone Else', 'is_primary' => true]);

        $level = app(CustomerRiskScore::class)->refresh($ada);

        $this->assertSame('high', $level->level);
        $this->assertGreaterThanOrEqual(2, count($level->reasons));
        $this->assertSame(1, CustomerRiskLevel::where('user_id', $ada->ID)->count());
        $this->assertSame('low', app(CustomerRiskScore::class)->refresh($this->person('clean'))->level);
    }

    public function test_the_cases_screen_lists_and_closes_a_case(): void
    {
        $opener = $this->person('opener');
        $customer = $this->person('ada');
        $case = app(ComplianceCases::class)->open($opener, $customer, 'Look into this');

        $this->actingAsAdministrator();

        Livewire::test(ListComplianceCases::class)
            ->assertCanSeeTableRecords([$case])
            ->callTableAction('close', $case, ['outcome' => 'All fine']);

        $this->assertSame('closed', ComplianceCase::find($case->id)->status);
    }

    public function test_the_values_register_says_which_numbers_still_need_confirming(): void
    {
        Artisan::call('compliance:values');
        $output = Artisan::output();

        $this->assertStringContainsString('Gaming tax rate', $output);
        $this->assertStringContainsString('still need confirming', $output);
    }
}

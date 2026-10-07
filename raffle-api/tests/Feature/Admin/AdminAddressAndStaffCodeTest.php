<?php

namespace Tests\Feature\Admin;

use App\Support\AdminPath;
use Tests\TestCase;

class AdminAddressAndStaffCodeTest extends TestCase
{
    public function test_the_admin_is_at_admin_unless_a_private_address_is_set(): void
    {
        $this->assertSame('admin', AdminPath::segment());
        $this->assertFalse(AdminPath::isHidden());
    }

    public function test_a_private_address_is_used_when_valid_and_junk_falls_back_to_admin(): void
    {
        config(['security.admin_path' => 'staff-k7q2x']);
        $this->assertSame('staff-k7q2x', AdminPath::segment());
        $this->assertTrue(AdminPath::isHidden());
        $this->assertSame(['staff-k7q2x', 'staff-k7q2x/*'], AdminPath::patterns());

        foreach (['', '../etc', 'a b', 'x/y', str_repeat('a', 61)] as $junk) {
            config(['security.admin_path' => $junk]);
            $this->assertSame('admin', AdminPath::segment(), "'{$junk}' should fall back");
        }

        config(['security.admin_path' => '/staff_1/']);
        $this->assertSame('staff_1', AdminPath::segment());
    }

    public function test_staff_code_is_off_by_default_until_real_email_is_set_up(): void
    {
        // Tests run with the log mailer, so the starting value must be "off":
        // nobody should be asked for a code that can never arrive.
        $this->assertFalse((bool) config('security.staff_two_step'));
    }
}

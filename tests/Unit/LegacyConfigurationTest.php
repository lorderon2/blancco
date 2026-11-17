<?php

namespace Tests\Unit;

use App\Domain\Installations\LegacyConfiguration;
use PHPUnit\Framework\TestCase;

class LegacyConfigurationTest extends TestCase
{
    public function test_idosell_home_conversion_preserves_dpk_and_exclusion_order(): void
    {
        $rules = new LegacyConfiguration;
        $pl = $rules->resolve('idosell', 'WINDOWS_10_HOME', 'Polska', true, true);
        $this->assertSame('1WIN 10 PRO PL', $pl['image_name_snapshot']);
        $this->assertFalse($pl['use_dpk']);
        $this->assertSame('ready', $pl['preparation_status']);
        $de = $rules->resolve('idosell', 'WINDOWS_10_HOME', 'Niemcy', true, true);
        $this->assertSame('blocked', $de['preparation_status']);
        $this->assertFalse($de['use_dpk']);
    }

    public function test_document_win10_requires_coa_but_manual_does_not(): void
    {
        $rules = new LegacyConfiguration;
        $this->assertSame('blocked', $rules->resolve('document', 'WINDOWS_10_PRO', 'Polska', false)['preparation_status']);
        $this->assertSame('ready', $rules->resolve('manual', 'WINDOWS_10_PRO', 'Polska', false)['preparation_status']);
    }

    public function test_department_image_preserves_exact_double_space_and_no_dpk(): void
    {
        $config = (new LegacyConfiguration)->resolve('department', 'WINDOWS_10_HOME', 'Niemcy', true);
        $this->assertSame('2WIN 10  HOME PL ODD', $config['image_name_snapshot']);
        $this->assertFalse($config['use_dpk']);
    }
}

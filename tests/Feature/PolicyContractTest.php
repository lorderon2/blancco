<?php

namespace Tests\Feature;

use App\Domain\Installations\ImportAssignment;
use App\Domain\Installations\SelectInstallation;
use App\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyContractTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): array
    {
        $contract = json_decode(file_get_contents(__DIR__.'/../../contracts/installation-policy-v1.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($s) => ['name' => $s['name'], 'value' => [$s]], $contract['scenarios']), 'value', 'name');
    }

    #[DataProvider('cases')]
    public function test_shared_policy(array $case): void
    {
        foreach ($case['rows'] as $row) {
            $job = app(ImportAssignment::class)->import(['source' => $row['order_source'], 'external_id' => $row['document_number'],
                'document_number' => $row['document_number'], 'serial' => 'CONTRACT123', 'windows_version' => 'WINDOWS_11_PRO', 'country' => 'Polska',
                'use_dpk' => $row['use_dpk'], 'assigned_at' => $row['source_assigned_at'], 'source_sequence' => $row['source_sequence']]);
            $job->update(array_intersect_key($row, array_flip(['first_valid_until', 'first_success_at', 'last_success_at', 'retry_until', 'preparation_status', 'image_name_snapshot', 'use_dpk', 'configuration_hash', 'blocked_reason', 'cancelled_at', 'lifecycle_status'])));
        }
        $selection = app(SelectInstallation::class)->forDevice(Device::first(), CarbonImmutable::parse($case['at']));
        $this->assertSame($case['decision'], $selection->decision->value);
        $this->assertSame($case['reason'], $selection->reason);
        $this->assertSame($case['document'], $selection->job?->assignment->order->document_number);
    }
}

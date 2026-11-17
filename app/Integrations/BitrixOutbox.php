<?php

namespace App\Integrations;

use App\Models\InstallationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BitrixOutbox
{
    public function enqueueHomeCheck(InstallationJob $job): void
    {
        DB::table('integration_outbox')->insertOrIgnore(['operation_key' => 'home-check-job-'.$job->id,
            'destination' => 'bitrix', 'type' => 'home_check', 'safe_payload' => json_encode(['job_id' => $job->id]),
            'state' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function dispatch(int $id): void
    {
        if (! config('blancco.bitrix.enabled') || ! config('blancco.bitrix.url')) {
            throw new \RuntimeException('BITRIX_NOT_CONFIGURED');
        }
        $lock = Cache::lock('bitrix-outbox-'.$id, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $row = DB::table('integration_outbox')->find($id);
            if (! $row || $row->destination !== 'bitrix' || $row->state !== 'pending') {
                return;
            }
            if ($row->type !== 'home_check') {
                throw new \RuntimeException('BITRIX_UNSUPPORTED_OPERATION');
            }
            $payload = json_decode($row->safe_payload, true, flags: JSON_THROW_ON_ERROR);
            $job = InstallationJob::with('assignment.device', 'assignment.order')->findOrFail($payload['job_id']);
            $device = $job->assignment->device;
            $order = $job->assignment->order;
            $license = DB::table('device_license_data')->where('device_id', $device->id)->where('kind', 'coa')->orderByDesc('checked_at')->first();
            $fields = ['TITLE' => 'HOME_'.$device->serial_original, 'CATEGORY_ID' => 36, 'STAGE_ID' => 'C36:UC_GONDCH',
                'UF_CRM_1713785135932' => $license ? Crypt::decryptString($license->encrypted_value) : null,
                'UF_CRM_DEAL_1715855801917' => $device->description, 'UF_CRM_DEAL_1715850854490' => $device->serial_original,
                'UF_CRM_DEAL_1754984060521' => $order->document_number, 'UF_CRM_1757341840130' => $order->country,
                'ORIGINATOR_ID' => 'blancco', 'ORIGIN_ID' => $row->operation_key];
            // Claim durably before the external write; an interrupted delivery needs reconciliation.
            $claimed = DB::table('integration_outbox')->where('id', $id)->where('state', 'pending')->update(['state' => 'sending', 'attempts' => DB::raw('attempts+1'), 'updated_at' => now()]);
            if (! $claimed) {
                return;
            }
            try {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()->post(rtrim(config('blancco.bitrix.url'), '/').'/crm.deal.add.json', ['fields' => $fields]);
                $result = $response->json();
                $external = is_array($result) ? ($result['result'] ?? null) : null;
                if ($response->successful() && (is_int($external) || is_string($external)) && ctype_digit((string) $external) && (int) $external > 0) {
                    DB::table('integration_outbox')->where('id', $id)->update(['state' => 'sent', 'external_result_id' => (string) $external, 'updated_at' => now()]);
                } else {
                    // A 5xx/invalid response may follow a committed write. Never create the deal again blindly.
                    DB::table('integration_outbox')->where('id', $id)->update(['state' => 'needs_review', 'updated_at' => now()]);
                }
            } catch (\Throwable $e) {
                DB::table('integration_outbox')->where('id', $id)->update(['state' => 'needs_review', 'updated_at' => now()]);
            }
        } finally {
            $lock->release();
        }
    }
}

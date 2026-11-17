<?php

namespace App\Domain\Installations;

final class LegacyConfiguration
{
    public const WINDOWS = ['WINDOWS_10_HOME', 'WINDOWS_10_PRO', 'WINDOWS_11_HOME', 'WINDOWS_11_PRO', 'WINDOWS_10_PRO_PRE', 'WINDOWS_11_PRO_PRE'];

    public const COUNTRIES = ['Polska' => 'PL', 'Pl' => 'PL', 'Czechy' => 'CZE', 'Finlandia' => 'FIN', 'Francja' => 'FRA', 'Belgia' => 'FRA', 'Niemcy' => 'GER', 'Austria' => 'GER', 'Szwajcaria' => 'GER', 'Włochy' => 'ITA', 'Portugalia' => 'POR', 'Słowacja' => 'SKA', 'Hiszpania' => 'ESP', 'Wielka Brytania' => 'ENG', 'Irlandia' => 'ENG', 'Holandia' => 'HOL'];

    public function resolve(string $source, string $windows, string $country, bool $useDpk, bool $hasCoa = false): array
    {
        $blocked = null;
        if (! in_array($windows, self::WINDOWS, true)) {
            $blocked = 'Nieobsługiwana wersja systemu.';
        }
        if ($source === 'idosell') {
            // Preserve order of the original iai.php rules.
            if (! in_array($country, ['Polska', 'Pl'], true)) {
                $useDpk = false;
                if (str_contains($windows, 'HOME')) {
                    $blocked = 'Wersja HOME poza Polską jest wyłączona z automatycznej instalacji.';
                }
            }
            if ($windows === 'WINDOWS_10_HOME') {
                $windows = 'WINDOWS_10_PRO';
            }
            if (in_array($windows, ['WINDOWS_10_HOME', 'WINDOWS_10_PRO'], true)) {
                $useDpk = false;
            }
        }
        $requiresCoa = in_array($source, ['idosell', 'document'], true) && in_array($windows, ['WINDOWS_10_PRO', 'WINDOWS_10_PRO_PRE'], true);
        if ($requiresCoa && ! $hasCoa) {
            $blocked ??= 'Brak wymaganego klucza COA w AIKEN.';
        }
        $short = str_replace(['WINDOWS_', '_PRE', '_'], ['', '', ' '], $windows);
        if ($source === 'department') {
            $country = 'Polska';
            $useDpk = false;
            if (str_ends_with($windows, '_PRE')) {
                $blocked ??= 'Mapowanie obrazu oddziałowego PRE wymaga potwierdzenia używanego wariantu starego procesu.';
            }
            $image = $windows === 'WINDOWS_10_HOME' ? '2WIN 10  HOME PL ODD' : "2WIN {$short} PL ODD";
        } else {
            $code = self::COUNTRIES[$country] ?? 'ENG';
            $image = $code === 'PL' ? "1WIN {$short} PL" : "{$code} {$short}";
        }

        return ['requires_coa' => $requiresCoa, 'windows_version' => $windows, 'country' => $country, 'image_name_snapshot' => $image, 'use_dpk' => $useDpk,
            'preparation_status' => $blocked ? 'blocked' : 'ready', 'blocked_reason' => $blocked, 'rule_version' => 'legacy-v1'];
    }
}

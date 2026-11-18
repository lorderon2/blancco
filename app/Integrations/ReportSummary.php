<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

class ReportSummary
{
    public function latest(array $reports): ?array
    {
        usort($reports, fn (array $a, array $b) => $this->timestamp($b['date'] ?? null) <=> $this->timestamp($a['date'] ?? null));

        return $reports[0] ?? null;
    }

    public function dateLabel(?string $date): string
    {
        try {
            return $date ? CarbonImmutable::parse($date)->setTimezone('Europe/Warsaw')->format('d.m.Y, H:i') : 'Brak daty';
        } catch (\Throwable) {
            return $date ?: 'Brak daty';
        }
    }

    private function timestamp(?string $date): int
    {
        try {
            return $date ? CarbonImmutable::parse($date)->timestamp : PHP_INT_MIN;
        } catch (\Throwable) {
            return PHP_INT_MIN;
        }
    }

    private function field(mixed $data, string $key): mixed
    {
        if (! is_array($data)) {
            return null;
        }
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        foreach ($data as $entry) {
            if (is_array($entry) && array_key_exists($key, $entry)) {
                return $entry[$key];
            }
        }

        return null;
    }

    private function entries(mixed $data, string $key): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (array_key_exists($key, $data)) {
            $value = $data[$key];

            return is_array($value) ? [$value] : [];
        }

        return array_values(array_map(fn ($entry) => $entry[$key], array_filter($data, fn ($entry) => is_array($entry) && isset($entry[$key]) && is_array($entry[$key]))));
    }

    private function size(mixed $bytes): ?string
    {
        return is_numeric($bytes) ? number_format((float) $bytes / 1073741824, 1, ',', ' ').' GiB' : null;
    }

    private function rows(array $values): array
    {
        return array_filter($values, fn ($value) => is_scalar($value) && (string) $value !== '');
    }

    public function sections(array $report): array
    {
        $hardware = $report['data']['blancco_hardware_report'] ?? [];
        $system = $hardware['system'] ?? [];
        $sections = ['Urządzenie' => $this->rows([
            'Numer seryjny' => $report['serial'] ?? $this->field($system, 'serial'),
            'Producent' => $this->field($system, 'manufacturer'), 'Model' => $this->field($system, 'model'),
            'Typ urządzenia' => $this->field($system, 'chassis_type'), 'Tryb BIOS' => $this->field($system, 'bios_mode'),
            'Secure Boot' => match ($this->field($system, 'secure_boot_state')) {
                'enabled' => 'Włączony', 'disabled' => 'Wyłączony', default => $this->field($system, 'secure_boot_state')
            },
        ])];
        $cpus = $hardware['processors'] ?? [];
        foreach ($this->entries($cpus, 'processor') as $index => $cpu) {
            $sections['Procesor '.($index + 1)] = $this->rows(['Model procesora' => $this->field($cpu, 'model'),
                'Rdzenie' => $this->field($cpu, 'cores'), 'Wątki' => $this->field($cpu, 'threads'),
                'Architektura' => $this->field($cpus, 'architecture')]);
        }
        $memory = $hardware['memory'] ?? [];
        $sections['Pamięć RAM'] = $this->rows(['Łączna pamięć' => $this->size($this->field($memory, 'total_memory')),
            'Liczba gniazd' => $this->field($memory, 'total_slots'), 'Wolne gniazda' => $this->field($memory, 'free_slots')]);
        foreach ($this->entries($hardware['disks'] ?? [], 'disk') as $index => $disk) {
            $sections['Dysk '.($index + 1)] = $this->rows(['Producent' => $this->field($disk, 'vendor'),
                'Model' => $this->field($disk, 'model'), 'Numer seryjny' => $this->field($disk, 'serial'),
                'Pojemność' => $this->size($this->field($disk, 'capacity')), 'Interfejs' => $this->field($disk, 'interface_type'),
                'Stan dysku' => match ($this->field($disk, 'health')) {
                    'good' => 'Dobry', 'bad' => 'Zły', default => $this->field($disk, 'health')
                }]);
        }
        foreach ($this->entries($hardware['batteries'] ?? [], 'battery') as $index => $battery) {
            $sections['Bateria '.($index + 1)] = $this->rows(['Model' => $this->field($battery, 'model'),
                'Poziom naładowania' => is_numeric($this->field($battery, 'level')) ? $this->field($battery, 'level').'%' : null,
                'Liczba cykli' => $this->field($battery, 'cycle_count')]);
        }
        foreach ($this->entries($report['data']['blancco_reimage_report']['reimages'] ?? [], 'reimage') as $index => $reimage) {
            $image = $this->field($reimage, 'image');
            $sections['Instalacja Reimage '.($index + 1)] = $this->rows(['Wynik' => $this->field($reimage, 'state'),
                'Obraz' => $this->field($image, 'image_name'), 'Edycja Windows' => $this->field($image, 'windows_edition'),
                'Wersja Windows' => $this->field($image, 'windows_version'), 'Początek' => $this->field($reimage, 'start_time') ? $this->dateLabel($this->field($reimage, 'start_time')) : null,
                'Koniec' => $this->field($reimage, 'end_time') ? $this->dateLabel($this->field($reimage, 'end_time')) : null, 'Czas trwania' => $this->field($reimage, 'elapsed_time'),
                'Rodzaj COA' => $this->field($this->field($reimage, 'dpk'), 'coa_type')]);
        }

        return array_filter($sections);
    }
}

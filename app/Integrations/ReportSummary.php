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

    private function label(string $key): string
    {
        return [
            'description' => 'Szczegóły raportu', 'document_id' => 'UUID raportu', 'document_log' => 'Historia raportu',
            'author' => 'Oprogramowanie', 'product_name' => 'Nazwa produktu', 'product_version' => 'Wersja oprogramowania',
            'date' => 'Data', 'clock_source' => 'Źródło czasu', 'description_entries' => 'Dane dodatkowe',
            'license_consumption_ids' => 'Wykorzystane licencje', 'license_consumption_id' => 'Identyfikator licencji',
            'system' => 'Urządzenie', 'bios' => 'BIOS', 'processors' => 'Procesory', 'processor' => 'Procesor',
            'motherboard' => 'Płyta główna', 'memory' => 'Pamięć RAM', 'memory_bank' => 'Moduł RAM',
            'tpm_devices' => 'Moduły TPM', 'tpm_device' => 'TPM', 'disks' => 'Dyski', 'disk' => 'Dysk',
            'network_interfaces' => 'Karty sieciowe', 'network_interface' => 'Karta sieciowa',
            'storage_controllers' => 'Kontrolery dysków', 'storage_controller' => 'Kontroler dysków',
            'sound_cards' => 'Karty dźwiękowe', 'sound_card' => 'Karta dźwiękowa', 'batteries' => 'Baterie', 'battery' => 'Bateria',
            'displays' => 'Ekrany', 'display' => 'Ekran', 'trackpads' => 'Panele dotykowe', 'trackpad' => 'Panel dotykowy',
            'keyboards' => 'Klawiatury', 'keyboard' => 'Klawiatura', 'usb_devices' => 'Urządzenia USB', 'usb_device' => 'Urządzenie USB',
            'id' => 'ID', 'internal_id' => 'ID wewnętrzne', 'manufacturer' => 'Producent', 'vendor' => 'Producent',
            'model' => 'Model', 'serial' => 'Numer seryjny', 'serial_number' => 'Numer seryjny', 'uuid' => 'UUID',
            'chassis_type' => 'Typ obudowy', 'asset_tag' => 'Oznaczenie inwentarzowe', 'sku_number' => 'Numer SKU',
            'bios_mode' => 'Tryb BIOS', 'secure_boot_state' => 'Secure Boot', 'autopilot' => 'Autopilot',
            'enrollment_status' => 'Stan rejestracji', 'enrollment_query_timestamp' => 'Data sprawdzenia rejestracji',
            'workflow' => 'Workflow', 'name' => 'Nazwa', 'version' => 'Wersja', 'result' => 'Wynik',
            'start_time' => 'Początek', 'end_time' => 'Koniec', 'elapsed_time' => 'Czas trwania',
            'rom_size' => 'Rozmiar ROM', 'features' => 'Funkcje', 'feature' => 'Funkcja',
            'total_cores' => 'Łączna liczba rdzeni', 'total_cpus' => 'Liczba procesorów', 'architecture' => 'Architektura',
            'nominal_frequency' => 'Częstotliwość nominalna', 'max_frequency' => 'Częstotliwość maksymalna',
            'cores' => 'Rdzenie', 'threads' => 'Wątki', 'hyperthreading' => 'Hyper-Threading', 'caches' => 'Pamięć podręczna',
            'cache' => 'Pamięć podręczna', 'type' => 'Typ', 'size' => 'Rozmiar', 'voltage' => 'Napięcie', 'external_clock' => 'Zegar zewnętrzny',
            'total_memory' => 'Łączna pamięć', 'physical_memory' => 'Pamięć fizyczna', 'type_detail' => 'Szczegóły typu',
            'form_factor' => 'Format', 'capacity' => 'Pojemność', 'hz' => 'Częstotliwość (Hz)', 'total_slots' => 'Liczba gniazd',
            'free_slots' => 'Wolne gniazda', 'firmware_revision' => 'Wersja firmware', 'interface_type' => 'Interfejs',
            'blocksize' => 'Rozmiar bloku', 'sectors' => 'Liczba sektorów', 'health' => 'Stan', 'region' => 'Obszar', 'status' => 'Status',
            'mac' => 'Adres MAC', 'vendor_id' => 'ID producenta', 'model_id' => 'ID modelu', 'driver' => 'Sterownik',
            'level' => 'Naładowanie (%)', 'charge_full' => 'Pełna pojemność (mAh)', 'charge_full_design' => 'Pojemność projektowa (mAh)',
            'voltage_min_design' => 'Minimalne napięcie projektowe (mV)', 'voltage_max_design' => 'Maksymalne napięcie projektowe (mV)',
            'voltage_now' => 'Aktualne napięcie (mV)', 'cycle_count' => 'Liczba cykli', 'year' => 'Rok produkcji',
            'resolution' => 'Rozdzielczość', 'refresh_rate' => 'Odświeżanie (Hz)', 'built_in' => 'Wbudowany',
            'blancco_reimage_report' => 'Instalacja Reimage — pełne dane', 'reimages' => 'Instalacje', 'reimage' => 'Instalacja',
            'dpk' => 'Licencja', 'coa_type' => 'Rodzaj COA', 'product_key' => 'Klucz produktu', 'product_key_id' => 'Identyfikator klucza produktu',
            'image' => 'Obraz', 'image_name' => 'Nazwa obrazu', 'windows_edition' => 'Edycja Windows',
            'windows_version' => 'Wersja Windows', 'state' => 'Wynik', 'value' => 'Wartość',
        ][$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    private function fullRows(mixed $data, string $prefix = ''): array
    {
        if (! is_array($data)) {
            return [$prefix ?: 'Wartość' => is_bool($data) ? ($data ? 'Tak' : 'Nie') : ($data === null || $data === '' ? 'Brak danych' : (string) $data)];
        }
        $rows = [];
        $counts = [];
        foreach ($data as $key => $value) {
            if ($key === '@type') {
                continue;
            }
            $path = is_int($key) ? $prefix : trim($prefix.' / '.$this->label((string) $key), ' /');
            foreach ($this->fullRows($value, $path) as $label => $text) {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
                $unique = $counts[$label] === 1 ? $label : $label.' ('.$counts[$label].')';
                while (array_key_exists($unique, $rows)) {
                    $unique .= ' (kolejny)';
                }
                $rows[$unique] = $text;
            }
        }

        return $rows;
    }

    public function fullSections(array $report): array
    {
        $sections = [];
        foreach ($report['data'] ?? [] as $key => $value) {
            if ($key === 'blancco_hardware_report') {
                foreach ($value as $component => $fields) {
                    $sections[$this->label($component)] = $this->fullRows($fields);
                }
            } else {
                $sections[$this->label($key)] = $this->fullRows($value);
            }
        }

        return array_filter($sections);
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
                'enabled' => 'Włączony', 'disabled' => 'Wyłączony', 'unsupported' => 'Nieobsługiwany', default => $this->field($system, 'secure_boot_state')
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
                'Rodzaj COA' => $this->field($this->field($reimage, 'dpk'), 'coa_type'),
                'Klucz produktu' => $this->field($this->field($reimage, 'dpk'), 'product_key'),
                'Identyfikator klucza produktu' => $this->field($this->field($reimage, 'dpk'), 'product_key_id')]);
        }

        return array_filter($sections);
    }
}

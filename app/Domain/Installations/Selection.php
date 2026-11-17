<?php

namespace App\Domain\Installations;

use App\Models\InstallationJob;

final readonly class Selection
{
    public function __construct(public Decision $decision, public string $reason, public ?InstallationJob $job = null) {}

    public function message(): string
    {
        return match ($this->reason) {
            'READY' => 'Zlecenie gotowe do instalacji.',
            'ALREADY_INSTALLED_TODAY' => 'Komputer był już instalowany dzisiaj. Potwierdź ponowienie lub wybierz instalację ręczną.',
            'EXPIRED' => 'Zlecenie jest nieaktywne. Wybierz instalację ręczną.',
            'INCOMPLETE' => 'Dane zlecenia są niekompletne. Poczekaj na synchronizację lub wybierz instalację ręczną.',
            'BLOCKED' => 'Zlecenie jest zablokowane: '.($this->job?->blocked_reason ?? 'sprawdź dane.'),
            'AMBIGUOUS_ASSIGNMENT' => 'Nie można jednoznacznie ustalić najnowszego przypisania urządzenia.',
            'IN_PROGRESS' => 'Istnieje nierozstrzygnięta próba instalacji tego urządzenia.',
            default => 'Brak aktywnego zlecenia. Wybierz instalację ręczną.',
        };
    }
}

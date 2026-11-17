<?php

namespace App\Domain\Installations;

enum Decision: string
{
    case Auto = 'AUTO';
    case AskRetry = 'ASK_RETRY';
    case Manual = 'MANUAL';
    case Wait = 'WAIT';
    case Blocked = 'BLOCKED';
    case Busy = 'BUSY';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Instalacja według zamówienia', self::AskRetry => 'Ponowna instalacja — wymagane potwierdzenie',
            self::Manual => 'Instalacja ręczna', self::Wait => 'Oczekiwanie na dane', self::Blocked => 'Zlecenie zablokowane',
            self::Busy => 'Próba instalacji wymaga zakończenia',
        };
    }
}

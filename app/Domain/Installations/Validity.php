<?php

namespace App\Domain\Installations;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class Validity
{
    public static function firstDeadline(DateTimeInterface $created): CarbonImmutable
    {
        return CarbonImmutable::instance($created)->setTimezone('Europe/Warsaw')->startOfDay()->addDays(3)->utc();
    }

    public static function retryDeadline(DateTimeInterface $success): CarbonImmutable
    {
        return CarbonImmutable::instance($success)->setTimezone('Europe/Warsaw')->startOfDay()->addDay()->utc();
    }
}

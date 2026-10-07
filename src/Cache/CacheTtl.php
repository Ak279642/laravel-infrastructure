<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Cache;

final class CacheTtl
{
    public const SECOND = 1;

    public const SECONDS_5 = 5;

    public const SECONDS_10 = 10;

    public const SECONDS_30 = 30;

    public const MINUTE = 60;

    public const MINUTES_2 = 120;

    public const MINUTES_5 = 300;

    public const MINUTES_10 = 600;

    public const MINUTES_15 = 900;

    public const MINUTES_30 = 1800;

    public const HOUR = 3600;

    public const HOURS_2 = 7200;

    public const HOURS_3 = 10800;

    public const HOURS_6 = 21600;

    public const HOURS_12 = 43200;

    public const DAY = 86400;

    public const DAYS_2 = 172800;

    public const DAYS_3 = 259200;

    public const DAYS_5 = 432000;

    public const DAYS_7 = 604800;

    public const DAYS_14 = 1209600;

    public const MONTH = 2592000;

    public const MONTHS_3 = 7776000;

    public const MONTHS_6 = 15552000;

    public const YEAR = 31536000;

    // Convenience aliases
    public const SHORT = self::MINUTES_5;

    public const MEDIUM = self::HOUR;

    public const LONG = self::HOURS_6;

    public const WEEK = self::DAYS_7;

    private function __construct() {}
}

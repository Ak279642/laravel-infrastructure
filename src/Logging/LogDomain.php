<?php

declare(strict_types=1);

namespace Ak279642\LaravelInfrastructure\Logging;

enum LogDomain: string
{
    case APPLICATION = 'application';
    case API = 'api';
    case WEBHOOK = 'webhook';
    case JOBS = 'jobs';
    case BUSINESS = 'business';
    case ERRORS = 'errors';
}

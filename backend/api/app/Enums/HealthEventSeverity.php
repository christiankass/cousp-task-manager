<?php

namespace App\Enums;

enum HealthEventSeverity: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case Critical = 'critical';
}

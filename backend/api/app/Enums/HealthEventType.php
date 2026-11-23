<?php

namespace App\Enums;

enum HealthEventType: string
{
    case Cholera = 'cholera';
    case Measles = 'measles';
    case Mpox = 'mpox';
    case HemorrhagicFever = 'hemorrhagic_fever';
    case Flood = 'flood';
    case Other = 'other';
}

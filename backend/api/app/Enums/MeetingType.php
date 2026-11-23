<?php

namespace App\Enums;

enum MeetingType: string
{
    case Coordination = 'coordination';
    case Technical = 'technical';
    case Management = 'management';
    case Crisis = 'crisis';
    case Other = 'other';
}

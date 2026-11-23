<?php

namespace App\Enums;

enum UnitType: string
{
    case Direction = 'direction';
    case Division = 'division';
    case Department = 'department';
    case Service = 'service';
    case Cell = 'cell';
    case Team = 'team';
}

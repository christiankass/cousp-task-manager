<?php

namespace App\Enums;

enum ApproverType: string
{
    case UnitManager = 'unit_manager';
    case ParentUnitManager = 'parent_unit_manager';
    case Role = 'role';
    case User = 'user';
}

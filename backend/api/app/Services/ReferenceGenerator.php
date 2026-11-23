<?php

namespace App\Services;

use App\Models\Institution;
use Illuminate\Support\Facades\DB;

/** Références par type de dossier : COUSP-EVT-2026-001, COUSP-REU-2026-014, COUSP-MIS-2026-003. */
class ReferenceGenerator
{
    public const EVENT = 'EVT';

    public const MEETING = 'REU';

    public const MISSION = 'MIS';

    public function next(Institution $institution, string $scope): string
    {
        $year = (int) now($institution->timezone)->format('Y');
        $key = ['institution_id' => $institution->id, 'scope' => $scope, 'year' => $year];

        // Verrou de ligne : deux créations simultanées ne peuvent pas obtenir le même numéro.
        $value = DB::transaction(function () use ($key) {
            DB::table('reference_counters')->insertOrIgnore($key + ['last_value' => 0]);
            $row = DB::table('reference_counters')->where($key)->lockForUpdate()->first();
            DB::table('reference_counters')->where($key)->update(['last_value' => $row->last_value + 1]);

            return $row->last_value + 1;
        });

        return sprintf('%s-%s-%d-%03d', $institution->code, $scope, $year, $value);
    }
}

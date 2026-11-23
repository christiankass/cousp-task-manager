<?php

namespace App\Services\Tasks;

use App\Models\Institution;
use Illuminate\Support\Facades\DB;

/** Références COUSP-2026-000001, uniques par institution et par année (§15). */
class TaskReferenceGenerator
{
    public function next(Institution $institution): string
    {
        $year = (int) now($institution->timezone)->format('Y');

        // Verrou de ligne : deux créations simultanées ne peuvent pas obtenir le même numéro.
        $value = DB::transaction(function () use ($institution, $year) {
            DB::table('task_reference_counters')->insertOrIgnore([
                'institution_id' => $institution->id, 'year' => $year, 'last_value' => 0,
            ]);

            $row = DB::table('task_reference_counters')
                ->where('institution_id', $institution->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            DB::table('task_reference_counters')
                ->where('institution_id', $institution->id)
                ->where('year', $year)
                ->update(['last_value' => $row->last_value + 1]);

            return $row->last_value + 1;
        });

        return sprintf('%s-%d-%06d', $institution->code, $year, $value);
    }
}

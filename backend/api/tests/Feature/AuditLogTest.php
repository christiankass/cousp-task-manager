<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_audit_log_is_append_only_in_the_database(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');
        $this->loginAs($agent, $cousp);

        $this->assertTrue(AuditLog::query()->where('action', 'auth.login')->where('user_id', $agent->id)->exists());

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['action' => 'falsifié']);
    }
}

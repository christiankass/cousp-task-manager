<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'institution_id', 'email', 'succeeded', 'failure_reason', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }
}

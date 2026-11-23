<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAttachment extends Model
{
    use BelongsToInstitution, HasUuids;

    protected $fillable = ['institution_id', 'task_id', 'uploaded_by', 'file_name', 'mime_type', 'file_size', 'storage_path', 'checksum'];

    protected $hidden = ['storage_path'];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

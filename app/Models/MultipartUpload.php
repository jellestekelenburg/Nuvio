<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultipartUpload extends Model
{
    public const string STATUS_INITIATED = 'initiated';

    public const string STATUS_UPLOADING = 'uploading';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_ABORTED = 'aborted';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'upload_id',
        'upload_file_id',
        'user_id',
        'parent_id',
        'client_id',
        'name',
        'relative_path',
        'content_type',
        'size',
        'part_size',
        'part_count',
        'reserved_bytes',
        's3_key',
        's3_upload_id',
        'status',
        'completed_file_id',
        'initiated_at',
        'completed_at',
        'aborted_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'part_size' => 'integer',
        'part_count' => 'integer',
        'reserved_bytes' => 'integer',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'aborted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    public function completedFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'completed_file_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_INITIATED,
            self::STATUS_UPLOADING,
        ], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_ABORTED,
            self::STATUS_FAILED,
        ], true);
    }
}

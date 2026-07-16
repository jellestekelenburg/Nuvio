<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $upload_id
 * @property string $upload_file_id
 * @property int $user_id
 * @property int $parent_id
 * @property string $client_id
 * @property string $name
 * @property string|null $relative_path
 * @property string|null $content_type
 * @property int $size
 * @property int $part_size
 * @property int $part_count
 * @property int $reserved_bytes
 * @property string $s3_key
 * @property string $s3_upload_id
 * @property string $status
 * @property int|null $completed_file_id
 * @property Carbon|null $initiated_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $aborted_at
 * @property-read User $user
 * @property-read File $parent
 * @property-read File|null $completedFile
 */
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
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

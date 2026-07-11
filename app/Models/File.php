<?php

namespace App\Models;

use App\Support\SizeFormatter;
use App\Traits\HasCreatorAndUpdater;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kalnoy\Nestedset\NodeTrait;

/**
 * @property int $id
 * @property string $name
 * @property string|null $path
 * @property string|null $storage_path
 * @property int|null $parent_id
 * @property bool $is_folder
 * @property string|null $mime
 * @property int|null $size
 * @property int $created_by
 * @property int $updated_by
 * @property Carbon|null $deleted_at
 */
class File extends Model
{
    use HasCreatorAndUpdater, NodeTrait, SoftDeletes;

    protected $fillable = [
        'name',
        'path',
        'storage_path',
        'parent_id',
        'is_folder',
        'mime',
        'size',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_folder' => 'boolean',
        'size' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    protected function owner(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes): string => (int) $attributes['created_by'] === (int) Auth::id()
                ? 'me'
                : ($this->user?->name ?? 'unknown'),
        );
    }

    public function isRoot()
    {
        return $this->parent_id == null;
    }

    public function getFileSize(): string
    {
        return SizeFormatter::formatBytes($this->size);
    }

    public function isOwnedBy($userId): bool
    {
        return $this->created_by == $userId;
    }

    public function moveToTrash()
    {
        $this->deleted_at = Carbon::now();

        return $this->save();
    }

    public function deleteForever(): void
    {
        $this->deleteFilesFromStorage([$this]);
        $this->forceDelete();
    }

    public function deleteFilesFromStorage($files): void
    {
        foreach ($files as $file) {
            if ($file->is_folder) {
                $this->deleteFilesFromStorage($file->children);
            } else {
                Storage::delete($file->storage_path);
            }
        }
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->parent) {
                return;
            }

            $model->path = (! $model->parent->isRoot() ? $model->parent->path.'/' : '').Str::slug($model->name);
        });
    }
}

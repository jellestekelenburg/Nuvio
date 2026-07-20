<?php

namespace App\Models;

use App\Support\SizeFormatter;
use App\Traits\HasCreatorAndUpdater;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read User|null $updater
 * @property-read File|null $parent
 * @property-read Collection<int, File> $children
 * @property-read Collection<int, File> $ancestors
 * @property-read string $owner
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function owner(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): string {
                if ((int) $attributes['created_by'] === (int) Auth::id()) {
                    return 'me';
                }

                $user = $this->user()->first();

                return $user instanceof User ? $user->name : 'unknown';
            },
        );
    }

    public function isRoot(): bool
    {
        return $this->parent_id == null;
    }

    public function getFileSize(): string
    {
        return SizeFormatter::formatBytes($this->size);
    }

    public function isOwnedBy(int|string|null $userId): bool
    {
        return $this->created_by == $userId;
    }

    public function moveToTrash(): bool
    {
        $this->deleted_at = Carbon::now();

        return $this->save();
    }

    public function deleteForever(): void
    {
        $this->deleteFilesFromStorage([$this]);
        $this->forceDelete();
    }

    /**
     * @param  iterable<File>  $files
     */
    public function deleteFilesFromStorage(iterable $files): void
    {
        foreach ($files as $file) {
            if ($file->is_folder) {
                $this->deleteFilesFromStorage($file->children);
            } else {
                if ($file->storage_path !== null) {
                    Storage::delete($file->storage_path);
                }
            }
        }
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (File $model): void {
            if (! $model->parent) {
                return;
            }

            $model->path = (! $model->parent->isRoot() ? $model->parent->path.'/' : '').Str::slug($model->name);
        });
    }
}

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
use Kalnoy\Nestedset\NodeTrait;
use LogicException;

/**
 * @property int $id
 * @property string $name
 * @property string|null $storage_path
 * @property int|null $parent_id
 * @property bool $is_folder
 * @property string|null $mime
 * @property int|null $size
 * @property int $created_by
 * @property int $updated_by
 * @property bool $permanently_delete
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

    protected static function booted(): void
    {
        static::updating(function (File $file): void {
            if ($file->isDirty('created_by')) {
                throw new LogicException(
                    'The owner of a file-tree node cannot be changed.',
                );
            }
        });
    }

    protected $fillable = [
        'name',
        'storage_path',
        'parent_id',
        'is_folder',
        'mime',
        'size',
        'created_by',
        'updated_by',
        'permanently_delete',
    ];

    protected $casts = [
        'is_folder' => 'boolean',
        'size' => 'integer',
        'permanently_delete' => 'boolean',
    ];

    /**
     * Keep every user's nested set isolated from all other users.
     *
     * @return array<int, string>
     */
    protected function getScopeAttributes(): array
    {
        return ['created_by'];
    }

    /**
     * Check every owner-scoped tree instead of an unscoped empty model.
     */
    public static function isBroken(): bool
    {
        return static::query()
            ->withoutGlobalScopes()
            ->distinct()
            ->pluck('created_by')
            ->contains(
                fn (int $userId): bool => static::scoped([
                    'created_by' => $userId,
                ])->isBroken(),
            );
    }

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

    public function isInAvailableTree(): bool
    {
        if (
            $this->trashed()
            || $this->permanently_delete
        ) {
            return false;
        }

        return ! $this->newNestedSetQuery()
            ->whereAncestorOf($this)
            ->where(function ($query): void {
                $query
                    ->whereNotNull($this->getDeletedAtColumn())
                    ->orWhere('permanently_delete', true);
            })
            ->exists();
    }

    public function isAvailableTreeTarget(): bool
    {
        return $this->is_folder && $this->isInAvailableTree();
    }

    public function moveToTrash(): bool
    {
        $this->deleted_at = Carbon::now();

        return $this->save();
    }
}

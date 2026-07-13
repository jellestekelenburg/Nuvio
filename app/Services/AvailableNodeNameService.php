<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Database\Eloquent\Builder;

final class AvailableNodeNameService
{
    /**
     * Determine an available name within a single target directory.
     *
     * Examples:
     * - document.pdf   → document.pdf
     * - document.pdf   → document-1.pdf
     * - document.pdf   → document-2.pdf
     * - .env           → .env-1
     *
     * @param  array<int, string>  $reservedNames  Names already reserved within the
     *                                             same upload or operation, but not yet stored in the database.
     */
    public function generate(
        File $targetParent,
        string $requestedName,
        array $reservedNames = [],
        ?int $ignoreNodeId = null,
    ): string {
        $requestedName = $this->normalizeName($requestedName);

        [$baseName, $extension] = $this->splitName($requestedName);

        $existingNames = $this->findConflictingNames(
            targetParent: $targetParent,
            requestedName: $requestedName,
            baseName: $baseName,
            extension: $extension,
            ignoreNodeId: $ignoreNodeId,
        );

        return $this->generateFromUnavailableNames(
            requestedName: $requestedName,
            unavailableNames: [...$existingNames, ...$reservedNames],
        );
    }

    /**
     * Determine an available name from an in-memory list of unavailable names.
     *
     * This is used while planning files for a target directory that does not
     * exist yet and therefore has no database records to inspect.
     *
     * @param  array<int, string>  $unavailableNames
     */
    public function generateFromUnavailableNames(
        string $requestedName,
        array $unavailableNames = [],
    ): string {
        $requestedName = $this->normalizeName($requestedName);
        [$baseName, $extension] = $this->splitName($requestedName);
        $unavailable = array_fill_keys($unavailableNames, true);

        if (! isset($unavailable[$requestedName])) {
            return $requestedName;
        }

        for ($suffix = 1; ; $suffix++) {
            $candidate = "{$baseName}-{$suffix}{$extension}";

            if (! isset($unavailable[$candidate])) {
                return $candidate;
            }
        }
    }

    /**
     * Retrieve only names that may belong to the same name family.
     *
     * For "document.pdf", the following names are searched for:
     * - document.pdf
     * - document-%.pdf
     *
     * The LIKE query may also return "document-backup.pdf".
     * Therefore, the results are filtered in PHP to include only numeric suffixes.
     *
     * @return array<int, string>
     */
    private function findConflictingNames(
        File $targetParent,
        string $requestedName,
        string $baseName,
        string $extension,
        ?int $ignoreNodeId,
    ): array {
        $likePattern = sprintf(
            '%s-%%%s',
            $this->escapeLikeValue($baseName),
            $this->escapeLikeValue($extension),
        );

        $possibleNames = File::query()
            ->select('name')
            ->where('parent_id', $targetParent->id)
            ->whereNull('deleted_at')
            ->when(
                $ignoreNodeId !== null,
                fn (Builder $query) => $query->where(
                    'id',
                    '!=',
                    $ignoreNodeId,
                ),
            )
            ->where(function (Builder $query) use (
                $requestedName,
                $likePattern,
            ) {
                $query
                    ->where('name', $requestedName)
                    ->orWhereRaw(
                        "name LIKE ? ESCAPE '!'",
                        [$likePattern],
                    );
            })
            ->pluck('name')
            ->all();

        $numberedVariantPattern = sprintf(
            '/^%s-[0-9]+%s$/u',
            preg_quote($baseName, '/'),
            preg_quote($extension, '/'),
        );

        return array_values(array_filter(
            $possibleNames,
            static fn (string $name): bool => $name === $requestedName
                || preg_match($numberedVariantPattern, $name) === 1,
        ));
    }

    private function normalizeName(string $name): string
    {
        // Remove any directory segments included in the provided name.
        $name = basename(str_replace('\\', '/', trim($name)));

        return $name !== '' ? $name : 'file';
    }

    /**
     * Split a name into its base name and extension.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        // Treat a dotfile such as ".env" as a name without an extension.
        if (
            str_starts_with($name, '.') &&
            substr_count($name, '.') === 1
        ) {
            return [$name, ''];
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($extension === '') {
            return [$name, ''];
        }

        $baseName = substr(
            $name,
            0,
            -(strlen($extension) + 1),
        );

        return [$baseName, '.'.$extension];
    }

    /**
     * Escape user input for a LIKE expression.
     *
     * "!" is explicitly configured as the escape character in the SQL query.
     */
    private function escapeLikeValue(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value,
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilesActionsRequest;
use App\Http\Requests\RenameFileRequest;
use App\Http\Requests\StoreFileRequest;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\TrashFilesRequest;
use App\Http\Resources\FileResource;
use App\Jobs\DeletePendingFiles;
use App\Models\File;
use App\Models\User;
use App\Services\FileListCache;
use App\Services\FileTreeMutationService;
use App\Services\StorageUserService;
use App\Services\StoreUploadedFile;
use App\Services\ZipCreatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use LogicException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FileController extends Controller
{
    private const int DEFAULT_PAGE_LIMIT = 50;

    private const int MAX_PAGE_LIMIT = 100;

    protected StorageUserService $storageUserService;

    public function __construct(
        StorageUserService $storageUserService,
        private readonly StoreUploadedFile $storeUploadedFile,
        private readonly FileTreeMutationService $fileTreeMutationService,
        private readonly FileListCache $fileListCache,
    ) {
        $this->storageUserService = $storageUserService;
    }

    /**
     * Render or return one authorized folder listing.
     */
    public function myFiles(
        Request $request,
        ?int $folder = null,
    ): JsonResponse|InertiaResponse {
        $user = $this->authenticatedUser($request);
        $sortableColumns = [
            'name' => 'files.name',
            'updated_at' => 'files.updated_at',
            'size' => 'files.size',
        ];

        if ($folder !== null) {
            $folder = File::query()
                ->whereKey($folder)
                ->where('created_by', $user->id)
                ->where('is_folder', true)
                ->whereNull('deleted_at')
                ->firstOrFail();

            abort_unless($folder->isAvailableTreeTarget(), 404);
        } else {
            $folder = $this->getRoot();
        }

        $parameters = $this->listingParameters(
            $request,
            $sortableColumns,
        );

        $files = $this->fileListCache->rememberListing(
            user: $user,
            folder: $folder,
            parameters: [
                'page' => $parameters['page'],
                'limit' => $parameters['limit'],
                'sort_by' => $parameters['sort_by'],
                'sort_direction' => $parameters['sort_direction'],
            ],
            resolver: fn (): array => $this->fileListingPayload(
                user: $user,
                folder: $folder,
                parameters: $parameters,
            ),
        );

        if ($request->wantsJson()) {
            return response()->json($files);
        }

        $sort = [
            'by' => $parameters['sort_by'],
            'direction' => $parameters['sort_direction'],
        ];

        $ancestorsAndFolder = $folder->newNestedSetQuery()
            ->whereAncestorOf($folder)
            ->defaultOrder()
            ->get()
            ->push($folder);
        $ancestorsAndFolder->loadMissing(['user:id,name', 'updater:id,name']);

        $ancestors = FileResource::collection($ancestorsAndFolder);
        $folder = new FileResource($folder);

        return Inertia::render('MyFiles', compact('files', 'folder', 'ancestors', 'sort'));
    }

    public function trash(Request $request): AnonymousResourceCollection|InertiaResponse
    {
        $user = $this->authenticatedUser($request);
        $limit = $this->paginationLimit($request);

        $files = File::onlyTrashed()
            ->with(['user:id,name', 'updater:id,name'])
            ->where('created_by', $user->id)
            ->where('permanently_delete', false)
            ->orderBy('is_folder', 'desc')
            ->orderBy('files.deleted_at', 'desc')
            ->orderBy('files.id')
            ->paginate($limit);

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        return Inertia::render('Trash', compact('files'));
    }

    /**
     * Create a folder and invalidate its parent's cached listings after commit.
     */
    public function createFolder(StoreFolderRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();
        $requestedParentId = $request->parent?->id;

        $targetParentId = $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data, $requestedParentId): int {
                $parent = $this->lockedParent($user, $requestedParentId);

                $file = new File;
                $file->is_folder = true;
                $file->name = $data['name'];
                $file->created_by = $user->id;
                $file->updated_by = $user->id;

                $parent->appendNode($file);

                return (int) $parent->getKey();
            },
        );

        $this->fileListCache->flushFolder($user, $targetParentId);

        return redirect()->back();
    }

    /**
     * Rename a file or folder and invalidate its parent listing after persistence.
     */
    public function rename(
        RenameFileRequest $request,
        File $file
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $parentId = $file->parent_id;

        $file->name = $request->validated('name');
        $file->save();

        if ($parentId !== null) {
            $this->fileListCache->flushFolder($user, $parentId);
        }

        return redirect()->back();
    }

    /**
     * Store uploaded files and invalidate the target folder listing after commit.
     */
    public function store(StoreFileRequest $request): void
    {
        $data = $request->validated();
        $user = $this->authenticatedUser($request);
        $requestedParentId = $request->parent?->id;
        $fileTree = $request->input('file_tree', []);

        /** @var array{uploaded_bytes: int, target_parent_id: int} $mutationResult */
        $mutationResult = $this->fileTreeMutationService->run(
            $user->id,
            function () use (
                $data,
                $fileTree,
                $requestedParentId,
                $user,
            ): array {
                $parent = $this->lockedParent($user, $requestedParentId);
                $uploadedBytes = 0;

                if (is_array($fileTree) && $fileTree !== []) {
                    $uploadedBytes = $this->saveFileTree(
                        $fileTree,
                        $parent,
                        $user,
                    );
                } else {
                    foreach ($data['files'] as $file) {
                        if ($file instanceof UploadedFile) {
                            $uploadedBytes += $this->saveFile(
                                $file,
                                $user,
                                $parent,
                            );
                        }
                    }
                }

                return [
                    'uploaded_bytes' => $uploadedBytes,
                    'target_parent_id' => (int) $parent->getKey(),
                ];
            },
        );

        $this->fileListCache->flushFolder(
            $user,
            $mutationResult['target_parent_id'],
        );

        $this->storageUserService->addUsage(
            $user,
            $mutationResult['uploaded_bytes'],
        );
    }

    private function getRoot(): File
    {
        return File::query()
            ->where('created_by', auth()->id())
            ->whereIsRoot()
            ->firstOrFail();
    }

    private function paginationLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', self::DEFAULT_PAGE_LIMIT);

        return max(1, min($limit, self::MAX_PAGE_LIMIT));
    }

    /**
     * @param  array<string, mixed>  $fileTree
     */
    public function saveFileTree(array $fileTree, File $parent, User $user): int
    {
        $total = 0;
        foreach ($fileTree as $name => $file) {
            if (is_array($file)) {

                $folder = new File;
                $folder->is_folder = true;
                $folder->name = $name;
                $folder->created_by = $user->id;
                $folder->updated_by = $user->id;

                $parent->appendNode($folder);
                $total += $this->saveFileTree($file, $folder, $user);
            } elseif ($file instanceof UploadedFile) {
                $total += $this->saveFile($file, $user, $parent);
            }
        }

        return $total;
    }

    private function saveFile(UploadedFile $file, User $user, File $parent): int
    {
        return (int) $this->storeUploadedFile
            ->handle(
                file: $file,
                user: $user,
                parent: $parent,
                name: $file->getClientOriginalName(),
            )
            ->size;
    }

    private function lockedParent(User $user, ?int $parentId): File
    {
        $parent = File::query()
            ->where('created_by', $user->id)
            ->where('is_folder', true)
            ->whereNull('deleted_at')
            ->when(
                $parentId !== null,
                fn ($query) => $query->whereKey($parentId),
                fn ($query) => $query->whereIsRoot(),
            )
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless($parent->isAvailableTreeTarget(), 404);

        return $parent;
    }

    /**
     * Move selected items to trash and invalidate every affected source listing.
     */
    public function destroy(FilesActionsRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();
        $requestedParentId = $request->parent?->id;

        /**
         * @var array{
         *     redirect_parent_id: int|null,
         *     source_parent_ids: list<int>
         * } $mutationResult
         */
        $mutationResult = $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data, $requestedParentId): array {
                $parent = $this->lockedParent($user, $requestedParentId);
                $sourceParentIds = [];

                $moveToTrash = function (File $file) use (&$sourceParentIds): void {
                    if ($file->parent_id !== null) {
                        $sourceParentIds[] = (int) $file->parent_id;
                    }

                    $file->moveToTrash();
                };

                if ($data['all']) {
                    $children = File::query()
                        ->where('parent_id', $parent->id)
                        ->where('created_by', $user->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($children as $child) {
                        $moveToTrash($child);
                    }
                }

                foreach ($data['ids'] ?? [] as $id) {
                    if (! is_int($id) && ! is_string($id)) {
                        continue;
                    }

                    $file = File::query()
                        ->whereKey($id)
                        ->where('created_by', $user->id)
                        ->lockForUpdate()
                        ->first();

                    if ($file) {
                        $moveToTrash($file);
                    }
                }

                return [
                    'redirect_parent_id' => $parent->isRoot()
                        ? null
                        : (int) $parent->id,
                    'source_parent_ids' => array_values(
                        array_unique($sourceParentIds),
                    ),
                ];
            },
        );

        foreach ($mutationResult['source_parent_ids'] as $sourceParentId) {
            $this->fileListCache->flushFolder(
                $user,
                $sourceParentId,
            );
        }

        $redirectParentId = $mutationResult['redirect_parent_id'];

        return $redirectParentId === null
            ? to_route('myFiles')
            : to_route('myFiles', ['folder' => $redirectParentId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function download(FilesActionsRequest $request, ZipCreatorService $zipCreator): array
    {
        $data = $request->validated();
        $parent = $request->parent ?? $this->getRoot();

        $all = $data['all'] ?? false;
        $ids = $data['ids'] ?? [];

        if (! is_array($ids)) {
            $ids = [];
        }

        if (! $all && empty($ids)) {
            return [
                'message' => 'Please select at least one file to download.',
            ];
        }

        if ($all) {
            $url = $zipCreator->createZip($parent->children);
            $filename = $parent->name.'.zip';
        } else {
            if (count($ids) === 1) {
                $file = File::query()->whereKey($ids[0])->firstOrFail();
                if ($file->is_folder) {
                    if ($file->children->isEmpty()) {
                        return [
                            'message' => 'This folder is empty.',
                        ];
                    }
                    $url = $zipCreator->createZip($file->children);
                    $filename = $file->name.'.zip';
                } else {
                    $url = $this->temporaryDownloadUrl($file);
                    $filename = $file->name;
                }
            } else {
                $file = File::query()->whereIn('id', $ids)->get();
                $url = $zipCreator->createZip($file);
                $filename = $parent->name.'.zip';
            }
        }

        return [
            'url' => $url,
            'filename' => $filename,
        ];
    }

    private function temporaryDownloadUrl(File $file): string
    {
        if ($file->storage_path === null) {
            throw new LogicException('A downloadable file must have a storage path.');
        }

        $fallbackName = Str::ascii($file->name) ?: 'download';
        $disposition = (new ResponseHeaderBag)->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->name,
            $fallbackName,
        );

        return Storage::temporaryUrl(
            $file->storage_path,
            now()->addMinutes(15),
            [
                'ResponseContentDisposition' => $disposition,
                'ResponseContentType' => $file->mime ?: 'application/octet-stream',
            ],
        );
    }

    /**
     * Restore selected items and invalidate every destination listing after commit.
     */
    public function restore(TrashFilesRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();

        /** @var list<int> $destinationParentIds */
        $destinationParentIds = $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data): array {
                $query = File::onlyTrashed()
                    ->where('created_by', $user->id)
                    ->where('permanently_delete', false);

                if (! ($data['all'] ?? false)) {
                    $query->whereIn('id', $data['ids'] ?? []);
                }

                $items = $query
                    ->lockForUpdate()
                    ->get();

                $destinationParentIds = [];

                foreach ($items as $item) {
                    if (
                        $item->restore()
                        && $item->parent_id !== null
                    ) {
                        $destinationParentIds[] = (int) $item->parent_id;
                    }
                }

                return array_values(
                    array_unique($destinationParentIds),
                );
            },
        );

        foreach ($destinationParentIds as $destinationParentId) {
            $this->fileListCache->flushFolder(
                $user,
                $destinationParentId,
            );
        }

        return to_route('trash');
    }

    public function deleteForever(TrashFilesRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();

        $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data): void {
                $query = File::onlyTrashed()
                    ->where('created_by', $user->id)
                    ->where('permanently_delete', false);

                if (! ($data['all'] ?? false)) {
                    $query->whereIn('id', $data['ids'] ?? []);
                }

                $updatedFiles = $query->update([
                    'permanently_delete' => true,
                    'updated_at' => now(),
                ]);

                if ($updatedFiles > 0) {
                    DeletePendingFiles::dispatch($user->id)
                        ->afterCommit();
                }
            },
        );

        return to_route('trash');
    }

    /**
     * Normalize listing parameters and resolve the corresponding database column.
     *
     * @param  array<string, string>  $sortableColumns
     * @return array{
     *     page: int,
     *     limit: int,
     *     sort_by: string,
     *     sort_direction: 'asc'|'desc',
     *     sort_column: string
     * }
     */
    private function listingParameters(
        Request $request,
        array $sortableColumns,
    ): array {
        $requestedSortBy = $request->query('sortBy', 'size');
        $sortBy = is_string($requestedSortBy)
            && array_key_exists($requestedSortBy, $sortableColumns)
                ? $requestedSortBy
                : 'size';

        $requestedSortDirection = $request->query('sortDirection', 'desc');
        $sortDirection = is_string($requestedSortDirection)
            && in_array(
                $requestedSortDirection,
                ['asc', 'desc'],
                true,
            )
                ? $requestedSortDirection
                : 'desc';

        $requestedPage = $request->query('page', 1);
        $page = is_numeric($requestedPage)
            ? max(1, (int) $requestedPage)
            : 1;

        return [
            'page' => $page,
            'limit' => $this->paginationLimit($request),
            'sort_by' => $sortBy,
            'sort_direction' => $sortDirection,
            'sort_column' => $sortableColumns[$sortBy],
        ];
    }

    /**
     * Query and serialize one exact page of an authorized folder listing.
     *
     * @param  array{
     *     page: int,
     *     limit: int,
     *     sort_by: string,
     *     sort_direction: 'asc'|'desc',
     *     sort_column: string
     * }  $parameters
     * @return array<string, mixed>
     */
    private function fileListingPayload(
        User $user,
        File $folder,
        array $parameters,
    ): array {
        $paginator = File::query()
            ->with(['user:id,name', 'updater:id,name'])
            ->where('parent_id', $folder->id)
            ->where('created_by', $user->id)
            ->orderBy('is_folder', 'desc')
            ->orderBy(
                $parameters['sort_column'],
                $parameters['sort_direction'],
            )
            ->orderBy('files.id')
            ->paginate(
                perPage: $parameters['limit'],
                page: $parameters['page'],
            )
            ->appends([
                'limit' => $parameters['limit'],
                'sortBy' => $parameters['sort_by'],
                'sortDirection' => $parameters['sort_direction'],
            ]);

        /** @var array<string, mixed> $payload */
        $payload = FileResource::collection($paginator)
            ->response()
            ->getData(true);

        return $payload;
    }
}

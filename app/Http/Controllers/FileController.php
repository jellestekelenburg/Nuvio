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
use App\Services\FileTreeMutationService;
use App\Services\StorageUserService;
use App\Services\StoreUploadedFile;
use App\Services\ZipCreatorService;
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
    ) {
        $this->storageUserService = $storageUserService;
    }

    public function myFiles(
        Request $request,
        ?int $folder = null,
    ): AnonymousResourceCollection|InertiaResponse {
        $user = $this->authenticatedUser($request);
        $sortableColumns = [
            'name' => 'files.name',
            'updated_at' => 'files.updated_at',
            'size' => 'files.size',
        ];

        $sortBy = $request->query('sortBy', 'size');
        $sortDirection = $request->query('sortDirection', 'desc');

        $sortColumn = $sortableColumns[$sortBy] ?? 'files.size';

        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $limit = $this->paginationLimit($request);

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

        $files = File::query()
            ->with(['user:id,name', 'updater:id,name'])
            ->where('parent_id', $folder->id)
            ->where('created_by', $user->id)
            ->orderBy('is_folder', 'desc')
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('files.id')
            ->paginate($limit)
            ->withQueryString();

        $files = FileResource::collection($files);

        if ($request->wantsJson()) {
            return $files;
        }

        $sort = [
            'by' => $sortBy,
            'direction' => $sortDirection,
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

    public function createFolder(StoreFolderRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();
        $parentId = $request->parent?->id;

        $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data, $parentId): void {
                $parent = $this->lockedParent($user, $parentId);

                $file = new File;
                $file->is_folder = true;
                $file->name = $data['name'];
                $file->created_by = $user->id;
                $file->updated_by = $user->id;

                $parent->appendNode($file);
            },
        );

        return redirect()->back();
    }

    public function rename(RenameFileRequest $request, File $file): RedirectResponse
    {
        $name = $request->validated('name');
        $file->name = $name;
        $file->save();

        return redirect()->back();
    }

    public function store(StoreFileRequest $request): void
    {
        $totalUploadedBytes = 0;

        $data = $request->validated();
        $user = $this->authenticatedUser($request);
        $parentId = $request->parent?->id;
        $fileTree = $request->input('file_tree', []);

        $totalUploadedBytes = $this->fileTreeMutationService->run(
            $user->id,
            function () use (
                $data,
                $fileTree,
                $parentId,
                $user,
            ): int {
                $parent = $this->lockedParent($user, $parentId);

                if (is_array($fileTree) && $fileTree !== []) {
                    return $this->saveFileTree($fileTree, $parent, $user);
                }

                $uploadedBytes = 0;

                foreach ($data['files'] as $file) {
                    if ($file instanceof UploadedFile) {
                        $uploadedBytes += $this->saveFile(
                            $file,
                            $user,
                            $parent,
                        );
                    }
                }

                return $uploadedBytes;
            },
        );

        $this->storageUserService->addUsage($user, $totalUploadedBytes);
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

    public function destroy(FilesActionsRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $data = $request->validated();
        $parentId = $request->parent?->id;

        $redirectParentId = $this->fileTreeMutationService->run(
            $user->id,
            function () use ($user, $data, $parentId): ?int {
                $parent = $this->lockedParent($user, $parentId);

                if ($data['all']) {
                    $children = File::query()
                        ->where('parent_id', $parent->id)
                        ->where('created_by', $user->id)
                        ->lockForUpdate()
                        ->get();

                    foreach ($children as $child) {
                        $child->moveToTrash();
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
                        $file->moveToTrash();
                    }
                }

                return $parent->isRoot() ? null : $parent->id;
            },
        );

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

    public function restore(TrashFilesRequest $request): RedirectResponse
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

                $children = $query
                    ->lockForUpdate()
                    ->get();

                foreach ($children as $child) {
                    $child->restore();
                }
            },
        );

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
}

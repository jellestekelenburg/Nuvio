<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadBatchRequest;
use App\Services\UploadBatchService;
use Illuminate\Http\JsonResponse;

class UploadBatchController extends Controller
{
    public function store(
        StoreUploadBatchRequest $request,
        UploadBatchService $service,
        string $uploadId,
        string $batchId,
    ): JsonResponse {

        $result = $service->store(
            user: $request->user(),
            uploadId: $uploadId,
            batchId: $batchId,
            files: $request->file('files', []),
            parentId: $request->integer('parent_id') ?: null,
            clientIds: $request->input('client_ids', []),
            relativePaths: $request->input('relative_paths', []),
        );

        return response()->json($result);
    }
}

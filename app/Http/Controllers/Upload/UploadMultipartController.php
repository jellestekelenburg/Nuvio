<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Http\Requests\AbortMultipartUploadRequest;
use App\Http\Requests\CompleteMultipartUploadRequest;
use App\Http\Requests\InitiateMultipartUploadRequest;
use App\Http\Requests\SignMultipartUploadPartsRequest;
use App\Services\UploadMultipartService;
use Illuminate\Http\JsonResponse;

class UploadMultipartController extends Controller
{
    public function initiate(
        InitiateMultipartUploadRequest $request,
        UploadMultipartService $service,
        string $uploadId,
        string $uploadFileId,
    ): JsonResponse {
        $result = $service->initiate(
            user: $request->user(),
            uploadId: $uploadId,
            uploadFileId: $uploadFileId,
            parentId: $request->integer('parent_id') ?: null,
        );

        return response()->json($result['body'], $result['status']);
    }

    public function sign(
        SignMultipartUploadPartsRequest $request,
        UploadMultipartService $service,
        string $uploadId,
        string $uploadFileId,
    ): JsonResponse {
        $result = $service->signParts(
            user: $request->user(),
            uploadId: $uploadId,
            uploadFileId: $uploadFileId,
            partNumbers: $request->validated('parts')
        );

        return response()->json($result['body'], $result['status']);
    }

    public function complete(
        CompleteMultipartUploadRequest $request,
        UploadMultipartService $service,
        string $uploadId,
        string $uploadFileId,
    ): JsonResponse {
        $result = $service->complete(
            user: $request->user(),
            uploadId: $uploadId,
            uploadFileId: $uploadFileId,
            parts: $request->validated('parts')
        );

        return response()->json($result['body'], $result['status']);
    }

    public function abort(
        AbortMultipartUploadRequest $request,
        UploadMultipartService $service,
        string $uploadId,
        string $uploadFileId,
    ): JsonResponse {
        $result = $service->abort(
            user: $request->user(),
            uploadId: $uploadId,
            uploadFileId: $uploadFileId,
        );

        return response()->json($result['body'], $result['status']);
    }
}

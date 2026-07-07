<?php

namespace App\Http\Controllers\Upload;


use App\Http\Controllers\Controller;
use App\Http\Requests\InitiateMultipartUploadRequest;
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

    public function sign(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Multipart part signing is not implemented yet.',
        ], 501);
    }

    public function complete(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Multipart completion is not implemented yet.',
        ], 501);
    }

    public function abort(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Multipart abort is not implemented yet.',
        ], 501);
    }
}

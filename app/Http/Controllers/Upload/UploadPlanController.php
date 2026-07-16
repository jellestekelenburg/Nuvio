<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadPlanRequest;
use App\Services\UploadPlanService;
use Illuminate\Http\JsonResponse;

class UploadPlanController extends Controller
{
    public function __invoke(UploadPlanRequest $request, UploadPlanService $service): JsonResponse
    {
        return response()->json(
            $service->makePlan(
                user: $this->authenticatedUser($request),
                files: $request->validated('files'),
                parentId: $request->validated('parent_id') ?? null,
            )
        );
    }
}

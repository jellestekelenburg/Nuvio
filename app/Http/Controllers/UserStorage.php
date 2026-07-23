<?php

namespace App\Http\Controllers;

use App\Services\StorageUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserStorage extends Controller
{
    public function __construct(
        private readonly StorageUserService $storageService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $storage = $this->storageService->getCachedOrRecalculate(
            $this->authenticatedUser($request),
        );

        return response()->json($storage);
    }
}

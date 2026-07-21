<?php

namespace App\Http\Controllers;

use App\Exceptions\FileMoveException;
use App\Http\Requests\MoveFilesRequest;
use App\Services\FileMoveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class FileMoveController extends Controller
{
    public function __construct(
        private readonly FileMoveService $fileMoveService
    ) {}

    /**
     * Move the requested selection and redirect back with the result.
     *
     * @throws ValidationException
     */
    public function __invoke(
        MoveFilesRequest $request
    ): RedirectResponse {
        try {
            $result = $this->fileMoveService->move(
                user: $this->authenticatedUser($request),
                selection: $request->moveSelection(),
                targetParentId: $request->targetParentId(),
            );
        } catch (FileMoveException $exception) {
            throw ValidationException::withMessages([
                'move' => $exception->getMessage(),
            ]);
        }

        return back()->with(
            'move_result',
            $result->toArray(),
        );
    }
}

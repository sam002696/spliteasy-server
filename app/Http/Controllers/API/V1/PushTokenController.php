<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\StorePushTokenRequest;
use App\Services\Notification\PushTokenService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PushTokenController extends Controller
{
    public function __construct(
        private readonly PushTokenService $pushTokenService
    ) {}

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        try {
            $pushToken = $this->pushTokenService->store($request->user(), $request->validated());

            return ApiResponseService::successResponse([
                'id' => $pushToken->id,
                'provider' => $pushToken->provider,
                'platform' => $pushToken->platform,
                'device_id' => $pushToken->device_id,
                'app_version' => $pushToken->app_version,
                'last_used_at' => $pushToken->last_used_at,
            ], 'Push token saved successfully.');
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'token' => ['required', 'string', 'max:255'],
            ]);

            $updatedCount = $this->pushTokenService->revoke($request->user(), $request->input('token'));

            return ApiResponseService::successResponse([
                'revoked' => $updatedCount > 0,
            ], 'Push token revoked successfully.');
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

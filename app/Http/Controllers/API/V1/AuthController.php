<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->register($request->validated());

            return ApiResponseService::successResponse($data, 'Registration successful.', 201);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login($request->validated());

            return ApiResponseService::successResponse($data, 'Login successful.');
        } catch (AuthenticationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 401);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return ApiResponseService::successResponse(null, 'Logout successful.');
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            $user = $this->authService->me($request->user());

            return ApiResponseService::successResponse($user, 'Authenticated user profile fetched successfully.');
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

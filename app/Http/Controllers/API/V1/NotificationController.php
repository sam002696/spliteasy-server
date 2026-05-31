<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\NotificationResource;
use App\Models\ActivityRecipient;
use App\Services\Notification\NotificationService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $notifications = $this->notificationService->getUserNotifications(
                $request->user(),
                $request->query('filter', 'all'),
                (int) $request->query('per_page', 20)
            );

            return ApiResponseService::successResponse(
                NotificationResource::collection($notifications),
                'Notifications fetched successfully.',
                200,
                [
                    'unread_count' => $this->notificationService->getUnreadCount($request->user()),
                    'pagination' => [
                        'current_page' => $notifications->currentPage(),
                        'per_page' => $notifications->perPage(),
                        'total' => $notifications->total(),
                        'last_page' => $notifications->lastPage(),
                    ],
                ]
            );
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function markAsRead(ActivityRecipient $notification, Request $request): JsonResponse
    {
        try {
            $notification = $this->notificationService->markAsRead($notification, $request->user());

            return ApiResponseService::successResponse(
                new NotificationResource($notification),
                'Notification marked as read.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $updatedCount = $this->notificationService->markAllAsRead($request->user());

            return ApiResponseService::successResponse([
                'updated_count' => $updatedCount,
                'unread_count' => 0,
            ], 'Notifications marked as read.');
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

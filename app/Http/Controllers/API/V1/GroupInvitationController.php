<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Group\GroupInvitationResource;
use App\Models\GroupInvitation;
use App\Services\Group\GroupService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class GroupInvitationController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService
    ) {}

    public function pending(Request $request): JsonResponse
    {
        try {
            $invitations = $this->groupService->getPendingInvitations($request->user());

            return ApiResponseService::successResponse(
                GroupInvitationResource::collection($invitations),
                'Pending invitations fetched successfully.'
            );
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function accept(GroupInvitation $invitation, Request $request): JsonResponse
    {
        try {
            $invitation = $this->groupService->acceptInvitation($invitation, $request->user());

            return ApiResponseService::successResponse(
                new GroupInvitationResource($invitation),
                'Invitation accepted successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function reject(GroupInvitation $invitation, Request $request): JsonResponse
    {
        try {
            $invitation = $this->groupService->rejectInvitation($invitation, $request->user());

            return ApiResponseService::successResponse(
                new GroupInvitationResource($invitation),
                'Invitation rejected successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

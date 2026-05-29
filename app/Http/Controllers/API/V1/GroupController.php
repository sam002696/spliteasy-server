<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Group\CreateGroupRequest;
use App\Http\Requests\Group\InviteMemberRequest;
use App\Http\Resources\Group\GroupInvitationResource;
use App\Http\Resources\Group\GroupMemberResource;
use App\Http\Resources\Group\GroupResource;
use App\Models\Group;
use App\Services\Group\GroupService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groupService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $groups = $this->groupService->getUserGroups(
                $request->user(),
                $request->query('filter', 'all')
            );

            return ApiResponseService::successResponse(
                GroupResource::collection($groups),
                'Groups fetched successfully.'
            );
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function store(CreateGroupRequest $request): JsonResponse
    {
        try {
            $group = $this->groupService->createGroup($request->user(), $request->validated());

            return ApiResponseService::successResponse(
                new GroupResource($group),
                'Group created successfully.',
                201
            );
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function show(Group $group, Request $request): JsonResponse
    {
        try {
            $group = $this->groupService->getGroupDetails($group, $request->user());

            return ApiResponseService::successResponse(
                new GroupResource($group),
                'Group details fetched successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function destroy(Group $group, Request $request): JsonResponse
    {
        try {
            $this->groupService->deleteGroup($group, $request->user());

            return ApiResponseService::successResponse(null, 'Group deleted successfully.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function leave(Group $group, Request $request): JsonResponse
    {
        try {
            $this->groupService->leaveGroup($group, $request->user());

            return ApiResponseService::successResponse(null, 'Group left successfully.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function removeMember(Group $group, int $memberId, Request $request): JsonResponse
    {
        try {
            $this->groupService->removeMember($group, $request->user(), $memberId);

            return ApiResponseService::successResponse(null, 'Member removed successfully.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function inviteMember(Group $group, InviteMemberRequest $request): JsonResponse
    {
        try {
            $invitation = $this->groupService->inviteMember(
                $group,
                $request->user(),
                $request->validated('email')
            );

            return ApiResponseService::successResponse(
                new GroupInvitationResource($invitation),
                'Invitation sent successfully.',
                201
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function members(Group $group, Request $request): JsonResponse
    {
        try {
            $members = $this->groupService->getGroupMembers($group, $request->user());

            return ApiResponseService::successResponse(
                GroupMemberResource::collection($members),
                'Group members fetched successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function balances(Group $group, Request $request): JsonResponse
    {
        try {
            $balances = $this->groupService->getBalancesSummary($group, $request->user());

            return ApiResponseService::successResponse($balances, 'Group balances fetched successfully.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

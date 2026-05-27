<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\CreateExpenseRequest;
use App\Http\Resources\Expense\ExpenseResource;
use App\Models\Expense;
use App\Models\Group;
use App\Services\Expense\ExpenseService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService
    ) {}

    public function index(Group $group, Request $request): JsonResponse
    {
        try {
            $expenses = $this->expenseService->getGroupExpenses($group, $request->user());

            return ApiResponseService::successResponse(
                ExpenseResource::collection($expenses),
                'Group expenses fetched successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function store(Group $group, CreateExpenseRequest $request): JsonResponse
    {
        try {
            $expense = $this->expenseService->createExpense($group, $request->user(), $request->validated());

            return ApiResponseService::successResponse(
                new ExpenseResource($expense),
                'Expense created successfully.',
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

    public function show(Expense $expense, Request $request): JsonResponse
    {
        try {
            $expense = $this->expenseService->getExpenseDetails($expense, $request->user());

            return ApiResponseService::successResponse(
                new ExpenseResource($expense),
                'Expense details fetched successfully.'
            );
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function destroy(Expense $expense, Request $request): JsonResponse
    {
        try {
            $this->expenseService->deleteExpense($expense, $request->user());

            return ApiResponseService::successResponse(null, 'Expense deleted successfully.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

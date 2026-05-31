<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\Balance\BalanceService;
use App\Services\ResponseBuilder\ApiResponseService;
use App\Services\Settlement\SettlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class BalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balanceService,
        private readonly SettlementService $settlementService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $balances = $this->balanceService->getUserBalances(
                $request->user(),
                $request->query('filter', 'open')
            );

            return ApiResponseService::successResponse($balances, 'Balances fetched successfully.');
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }

    public function markSettled(Group $group, User $user, Request $request): JsonResponse
    {
        try {
            $settlement = $this->settlementService->markBalanceSettled($group, $request->user(), $user);

            return ApiResponseService::successResponse([
                'id' => $settlement->id,
                'group' => [
                    'id' => $settlement->group->id,
                    'name' => $settlement->group->name,
                    'base_currency' => $settlement->group->base_currency,
                ],
                'paid_by' => [
                    'id' => $settlement->paidBy->id,
                    'name' => $settlement->paidBy->name,
                    'email' => $settlement->paidBy->email,
                ],
                'paid_to' => [
                    'id' => $settlement->paidTo->id,
                    'name' => $settlement->paidTo->name,
                    'email' => $settlement->paidTo->email,
                ],
                'amount' => $settlement->amount,
                'currency' => $settlement->currency,
                'settled_at' => $settlement->settled_at,
            ], 'Balance marked as settled.');
        } catch (AuthorizationException $exception) {
            return ApiResponseService::errorResponse($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponseService::handleValidationError($exception);
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

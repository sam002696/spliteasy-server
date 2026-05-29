<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\Balance\BalanceService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class BalanceController extends Controller
{
    public function __construct(
        private readonly BalanceService $balanceService
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
}

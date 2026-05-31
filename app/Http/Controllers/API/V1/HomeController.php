<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Group\GroupResource;
use App\Services\Home\HomeService;
use App\Services\ResponseBuilder\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $homeService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $home = $this->homeService->getHomeData($request->user());

            return ApiResponseService::successResponse([
                'summary' => $home['summary'],
                'active_groups_count' => $home['active_groups_count'],
                'active_groups' => GroupResource::collection($home['active_groups']),
                'recent_activities' => $home['recent_activities'],
            ], 'Home data fetched successfully.');
        } catch (Throwable $exception) {
            return ApiResponseService::handleUnexpectedError($exception);
        }
    }
}

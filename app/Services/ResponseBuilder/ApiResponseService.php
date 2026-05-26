<?php

namespace App\Services\ResponseBuilder;

use Illuminate\Validation\ValidationException;

class ApiResponseService
{
    public static function successResponse($data, $message, $statusCode = 200, $meta = null)
    {
        $response = [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];

        if ($meta) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    public static function handleValidationError(ValidationException $exception)
    {
        $errors = $exception->errors();
        $firstErrorMessage = collect($errors)->first()[0];

        return response()->json([
            'data' => null,
            'status' => 'error',
            'message' => $firstErrorMessage,
            'errors' => $errors
        ], 422);
    }

    public static function handleUnexpectedError(\Throwable $exception)
    {
        return response()->json([
            'data' => null,
            'status' => 'error',
            'message' => $exception->getMessage(),
            'errors' => app()->environment('local') ? $exception->getTrace() : null,
        ], 500);
    }

    public static function errorResponse($message, $statusCode)
    {
        return response()->json([
            'data' => null,
            'status' => 'error',
            'message' => $message
        ], $statusCode);
    }
}

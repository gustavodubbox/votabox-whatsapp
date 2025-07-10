<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VotaBoxService;
use Illuminate\Http\JsonResponse;

class VotaBoxController extends Controller
{
    protected VotaBoxService $votaBoxService;

    public function __construct(VotaBoxService $votaBoxService)
    {
        $this->votaBoxService = $votaBoxService;
    }

    public function getTags(): JsonResponse
    {
        $tags = $this->votaBoxService->getTags();
        return response()->json($tags);
    }

    public function getSurveys(): JsonResponse
    {
        $surveys = $this->votaBoxService->getSurveys();
        // Adiciona um tratamento de erro básico
        if (isset($surveys['success']) && $surveys['success'] === false) {
             return response()->json($surveys, 500);
        }
        return response()->json($surveys);
    }
}
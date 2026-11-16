<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\HeroSlide;
use App\Support\PublicData;
use Illuminate\Http\JsonResponse;

class HeroSlideController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PublicData::strip(HeroSlide::active()->orderBy('sort_order')->get()));
    }
}

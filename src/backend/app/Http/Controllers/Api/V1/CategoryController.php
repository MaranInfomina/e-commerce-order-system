<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/categories',
        summary: 'List all categories',
        responses: [
            new OA\Response(response: 200, description: 'Categories', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Category')),
            ])),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        $categories = Cache::remember(
            'categories:all',
            3600,
            fn () => Category::query()->orderBy('name')->get(),
        );

        return CategoryResource::collection($categories);
    }
}

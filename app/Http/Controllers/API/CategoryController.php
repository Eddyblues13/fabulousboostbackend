<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $categories = Category::orderBy('category_title')
                ->get(['id', 'category_title', 'category_description']);

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load categories'
            ], 500);
        }
    }


    public function allSmmCategories(): JsonResponse
    {
        try {
            // Use status field (1 = active, 0 = inactive)
            // If status column doesn't exist, get all categories
            $categories = Category::select('id', 'category_title', 'category_description', 'image', 'status')
                ->where('status', 1)
                ->orderBy('category_title')
                ->get()
                ->map(function($category) {
                    // Generate slug from category_title
                    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $category->category_title), '-'));
                    
                    // Generate icon based on category title
                    $icon = '📱';
                    $title = strtolower($category->category_title);
                    if (strpos($title, 'instagram') !== false) $icon = '📷';
                    elseif (strpos($title, 'tiktok') !== false) $icon = '🎵';
                    elseif (strpos($title, 'facebook') !== false) $icon = '👥';
                    elseif (strpos($title, 'twitter') !== false || strpos($title, 'x') !== false) $icon = '🐦';
                    elseif (strpos($title, 'youtube') !== false) $icon = '▶️';
                    elseif (strpos($title, 'telegram') !== false) $icon = '✈️';
                    
                    return [
                        'id' => $category->id,
                        'name' => $category->category_title,
                        'category_title' => $category->category_title,
                        'slug' => $slug,
                        'icon' => $icon,
                        'description' => $category->category_description,
                        'image' => $category->image,
                    ];
                });

            return response()->json($categories);
        } catch (\Exception $e) {
            \Log::error('CategoryController allSmmCategories error: ' . $e->getMessage());
            // Try without status filter as fallback
            try {
                $categories = Category::select('id', 'category_title', 'category_description', 'image')
                    ->orderBy('category_title')
                    ->get()
                    ->map(function($category) {
                        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $category->category_title), '-'));
                        $icon = '📱';
                        $title = strtolower($category->category_title);
                        if (strpos($title, 'instagram') !== false) $icon = '📷';
                        elseif (strpos($title, 'tiktok') !== false) $icon = '🎵';
                        elseif (strpos($title, 'facebook') !== false) $icon = '👥';
                        elseif (strpos($title, 'twitter') !== false || strpos($title, 'x') !== false) $icon = '🐦';
                        elseif (strpos($title, 'youtube') !== false) $icon = '▶️';
                        elseif (strpos($title, 'telegram') !== false) $icon = '✈️';
                        
                        return [
                            'id' => $category->id,
                            'name' => $category->category_title,
                            'category_title' => $category->category_title,
                            'slug' => $slug,
                            'icon' => $icon,
                            'description' => $category->category_description,
                            'image' => $category->image,
                        ];
                    });
                return response()->json($categories);
            } catch (\Exception $e2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to load categories',
                    'error' => $e2->getMessage()
                ], 500);
            }
        }
    }
}

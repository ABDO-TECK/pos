<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Repositories\CategoryRepository;
use App\Helpers\Cache;


class CategoryController extends Controller {

    private CategoryRepository $categoryRepo;

    public function __construct(CategoryRepository $categoryRepo) {
        $this->categoryRepo = $categoryRepo;
    }

    public function index() {
        $filters = [];
        $filters += $this->getPaginationParams();

        // Cache only unfiltered requests
        $cacheKey = empty($filters) ? 'categories_all' : null;
        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                if (isset($cached['pagination'])) {
                    return Response::cacheable($cached['data'], 300, null, ['pagination' => $cached['pagination']]);
                }
                return Response::cacheable($cached, 300);
            }
        }

        $result = $this->categoryRepo->all($filters);

        if (isset($result['pagination'])) {
            if ($cacheKey) Cache::set($cacheKey, $result, 300);
            return Response::cacheable($result['data'], 300, null, ['pagination' => $result['pagination']]);
        } else {
            $data = $result['data'] ?? $result;
            if ($cacheKey) Cache::set($cacheKey, $data, 300);
            return Response::cacheable($data, 300);
        }
    }

    public function store() {
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        return $this->withTransaction(function () use ($data) {
            $id = $this->categoryRepo->create(['name' => $data['name']]);
            \App\Helpers\EventDispatcher::dispatch('category.created', ['id' => $id]);
            return Response::success(['id' => $id, 'name' => $data['name']], 'Category created', 201);
        });
    }

    public function update(string $id) {
        $request = new \App\Requests\CategoryRequest($this->getBody());
        $data = $request->validated();

        return $this->withTransaction(function () use ($id, $data) {
            $this->categoryRepo->update((int)$id, ['name' => $data['name']]);
            \App\Helpers\EventDispatcher::dispatch('category.updated', ['id' => (int)$id]);
            return Response::success(['id' => (int)$id, 'name' => $data['name']], 'Category updated');
        });
    }

    public function destroy(string $id) {
        return $this->withTransaction(function () use ($id) {
            $this->categoryRepo->delete((int)$id);
            \App\Helpers\EventDispatcher::dispatch('category.deleted', ['id' => (int)$id]);
            return Response::success(null, 'Category deleted');
        });
    }
}



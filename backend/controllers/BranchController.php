<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\Response;
use App\Models\Branch;

class BranchController extends Controller {
    private Branch $branchModel;

    public function __construct(Branch $branchModel) {
        $this->branchModel = $branchModel;
    }

    public function index() {
        return Response::cacheable($this->branchModel->all(), 300);
    }

    public function store() {
        $data = $this->getBody();
        if (empty($data['name'])) {
            return Response::error('اسم الفرع مطلوب', 422);
        }
        $id = $this->branchModel->create($data);
        return Response::success(['id' => $id], 'Branch created', 201);
    }

    public function update(string $id) {
        $data = $this->getBody();
        if (empty($data['name'])) {
            return Response::error('اسم الفرع مطلوب', 422);
        }
        $this->branchModel->update((int)$id, $data);
        return Response::success(null, 'Branch updated');
    }
}

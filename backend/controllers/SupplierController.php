<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Controller;
use App\Core\ValidationException;
use App\Helpers\ErrorCodes;
use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\SupplierService;
use App\Repositories\SupplierRepository;
use App\Requests\SupplierRequest;
use Throwable;


class SupplierController extends Controller {

    private SupplierRepository $supplierRepo;
    private SupplierService  $supplierService;
    private AuthService      $authService;

    public function __construct(SupplierRepository $supplierRepo, SupplierService $supplierService, AuthService $authService) {
        $this->supplierRepo     = $supplierRepo;
        $this->supplierService  = $supplierService;
        $this->authService      = $authService;
    }

    public function index() {
        $filters = [];
        if ($this->getParam('search'))  $filters['search']  = $this->getParam('search');
        $filters += $this->getPaginationParams();

        $result = $this->supplierRepo->all($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    public function show(string $id) {
        $data = $this->supplierRepo->getLedger((int)$id);
        if (!$data['supplier']) {
            return Response::notFound('Supplier not found');
        }
        return Response::success($data);
    }

    public function store() {
        try {
            $request = new SupplierRequest($this->getBody());
            $data = $request->validated();

            $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
            return $this->withTransaction(function () use ($data) {
                $id       = $this->supplierRepo->create($data);
                $supplier = $this->supplierRepo->findById($id);
                return Response::success($supplier, 'Supplier created', 201);
            });
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        }
    }

    public function update(string $id) {
        try {
            $request = new SupplierRequest($this->getBody());
            $data = $request->validated();

            $supplier = $this->supplierRepo->findById((int)$id);
            if (!$supplier) return Response::notFound('Supplier not found');

            $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
            return $this->withTransaction(function () use ($id, $data) {
                $this->supplierRepo->update((int)$id, $data);
                return Response::success($this->supplierRepo->findById((int)$id), 'Supplier updated');
            });
        } catch (ValidationException $e) {
            return Response::error('فشل التحقق من صحة البيانات', 422, $e->getErrors(), ErrorCodes::VALIDATION_FAILED);
        }
    }

    public function destroy(string $id) {
        $supplier = $this->supplierRepo->findById((int)$id);
        if (!$supplier) return Response::notFound('Supplier not found');
        return $this->withTransaction(function () use ($id) {
            $this->supplierRepo->delete((int)$id);
            return Response::success(null, 'Supplier deleted');
        });
    }



    /**
     * POST /api/suppliers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب المورد
     */
    public function addPayment(string $id) {
        $sid  = (int)$id;
        $data = $this->getBody();
        $auth = $this->authService->user();

        try {
            $ledger = $this->supplierService->addPayment($sid, $data, $auth);
            return Response::success($ledger, 'تم تسجيل الدفعة');
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage(), ErrorCodes::SUPPLIER_NOT_FOUND) : Response::error($e->getMessage(), $code, null, ErrorCodes::INVALID_AMOUNT);
        }
    }

    /**
     * PUT /api/suppliers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        try {
            $ledger = $this->supplierService->updateLedgerEntry($eid, $data);
            return Response::success($ledger, 'تم تحديث القيد');
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage(), ErrorCodes::SUPPLIER_NOT_FOUND) : Response::error($e->getMessage(), $code, null, ErrorCodes::SERVER_ERROR);
        }
    }

    /**
     * DELETE /api/suppliers/ledger/{entryId}
     */
    public function deleteLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;

        try {
            $ledger = $this->supplierService->deleteLedgerEntry($eid);
            return Response::success($ledger, 'تم حذف القيد');
        } catch (Throwable $e) {
            $code = $e->getCode() ?: 500;
            return $code === 404 ? Response::notFound($e->getMessage(), ErrorCodes::SUPPLIER_NOT_FOUND) : Response::error($e->getMessage(), $code, null, ErrorCodes::SERVER_ERROR);
        }
    }
}



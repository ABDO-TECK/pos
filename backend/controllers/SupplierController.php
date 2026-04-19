<?php

class SupplierController extends Controller {

    private Supplier         $supplierModel;
    private Product          $productModel;
    private InventoryService $inventoryService;

    public function __construct() {
        $this->supplierModel    = new Supplier();
        $this->productModel     = new Product();
        $this->inventoryService = new InventoryService();
    }

    public function index() {
        $filters = [];
        if ($this->getParam('search'))  $filters['search']  = $this->getParam('search');
        if ($this->getParam('page'))    $filters['page']    = $this->getParam('page');
        if ($this->getParam('limit'))   $filters['limit']   = $this->getParam('limit');

        $result = $this->supplierModel->all($filters);

        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    public function show(string $id) {
        $data = $this->supplierModel->getLedger((int)$id);
        if (!$data['supplier']) {
            return Response::notFound('Supplier not found');
        }
        return Response::success($data);
    }

    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
        $id       = $this->supplierModel->create($data);
        $supplier = $this->supplierModel->findById($id);
        return Response::success($supplier, 'Supplier created', 201);
    }

    public function update(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) return Response::notFound('Supplier not found');

        $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
        $this->supplierModel->update((int)$id, $data);
        return Response::success($this->supplierModel->findById((int)$id), 'Supplier updated');
    }

    public function destroy(string $id) {
        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) return Response::notFound('Supplier not found');
        $this->supplierModel->delete((int)$id);
        return Response::success(null, 'Supplier deleted');
    }

    /** Single-item purchase (legacy) */
    public function purchase() {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'supplier_id' => 'required',
            'product_id'  => 'required',
            'quantity'    => 'required|numeric',
            'cost'        => 'required|numeric',
        ]);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$data['product_id']);
        if (!$product) return Response::notFound('Product not found');

        $supplier = $this->supplierModel->findById((int)$data['supplier_id']);
        if (!$supplier) return Response::notFound('Supplier not found');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->supplierModel->createPurchase($data);
            $this->productModel->incrementQuantity((int)$data['product_id'], (int)$data['quantity']);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            return Response::serverError('Failed to record purchase');
        }

        return Response::success([
            'product' => $this->productModel->findById((int)$data['product_id']),
        ], 'Purchase recorded and stock updated', 201);
    }

    /** List purchase invoices (like sales list) */
    public function purchaseInvoices() {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date'        => $this->getParam('date'),
            'month'       => $this->getParam('month'),
            'year'        => $this->getParam('year'),
            'page'        => $this->getParam('page'),
            'limit'       => $this->getParam('limit'),
        ];
        
        $result = $this->supplierModel->getPurchaseInvoices($filters);
        if (isset($result['pagination'])) {
            return Response::success($result['data'], null, 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    /** Get single purchase invoice detail (like sales detail) */
    public function purchaseInvoiceDetail(string $id) {
        $invoice = $this->supplierModel->getPurchaseInvoice((int)$id);
        if (!$invoice) return Response::notFound('Purchase invoice not found');
        return Response::success($invoice);
    }

    /** Delete a purchase invoice and restore stock */
    public function purchaseInvoiceDelete(string $id) {
        $result = $this->inventoryService->deletePurchaseInvoice((int)$id);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::serverError($result['error']);
        }

        return Response::success(null, 'Purchase invoice deleted and stock restored');
    }

    /** Legacy flat purchases list */
    public function purchases() {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date_from'   => $this->getParam('date_from'),
            'date_to'     => $this->getParam('date_to'),
        ];
        return Response::success($this->supplierModel->getPurchases($filters));
    }

    /** Bulk purchase — creates a purchase invoice + items */
    public function purchaseBulk() {
        $data   = $this->getBody();
        $auth   = $_SERVER['AUTH_USER'];
        $result = $this->inventoryService->processBulkPurchase($data, $auth);

        if (!$result['ok']) {
            $code = $result['code'] ?? 500;
            return $code === 404
                ? Response::notFound($result['error'])
                : Response::error($result['error'], $code);
        }

        $isUpdate = $result['is_update'] ?? false;
        return Response::success([
            'invoice_id'      => $result['invoice_id'],
            'items_processed' => $result['items_processed'],
        ], $isUpdate ? 'Purchase invoice updated' : 'Bulk purchase recorded', $isUpdate ? 200 : 201);
    }

    /**
     * POST /api/suppliers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب المورد
     */
    public function addPayment(string $id) {
        $sid  = (int)$id;
        $data = $this->getBody();

        $supplier = $this->supplierModel->findById($sid);
        if (!$supplier) {
            return Response::notFound('المورد غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] === 'debit' ? 'debit' : 'credit';

        $auth = $_SERVER['AUTH_USER'];
        $this->supplierModel->addLedgerEntry([
            'supplier_id'         => $sid,
            'type'                => $type,
            'amount'              => $amount,
            'description'         => $data['description'] ?? 'دفعة نقدية للمورد',
            'purchase_invoice_id' => null,
            'created_by'          => $auth['id'],
        ]);

        // إعادة كشف الحساب المحدَّث
        return Response::success($this->supplierModel->getLedger($sid), 'تم تسجيل الدفعة');
    }

    /**
     * PUT /api/suppliers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        $entry = $this->supplierModel->getLedgerEntry($eid);
        if (!$entry) {
            return Response::notFound('القيد غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
        }
        $type = $data['type'] ?? $entry['type'];
        if (!in_array($type, ['debit', 'credit'])) {
            return Response::error('نوع القيد غير صحيح', 422);
        }

        $this->supplierModel->updateLedgerEntry($eid, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        // إعادة كشف الحساب المحدَّث
        return Response::success($this->supplierModel->getLedger((int)$entry['supplier_id']), 'تم تحديث القيد');
    }
}



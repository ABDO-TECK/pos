<?php

class SupplierController extends Controller {

    private Supplier $supplierModel;
    private Product  $productModel;

    public function __construct() {
        $this->supplierModel = new Supplier();
        $this->productModel  = new Product();
    }

    public function index(): void {
        Response::success($this->supplierModel->all());
    }

    public function show(string $id): void {
        $data = $this->supplierModel->getLedger((int)$id);
        if (!$data['supplier']) {
            Response::notFound('Supplier not found');
        }
        Response::success($data);
    }

    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
        $id       = $this->supplierModel->create($data);
        $supplier = $this->supplierModel->findById($id);
        Response::success($supplier, 'Supplier created', 201);
    }

    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) Response::notFound('Supplier not found');

        $data['initial_balance'] = (float)($data['initial_balance'] ?? 0);
        $this->supplierModel->update((int)$id, $data);
        Response::success($this->supplierModel->findById((int)$id), 'Supplier updated');
    }

    public function destroy(string $id): void {
        $supplier = $this->supplierModel->findById((int)$id);
        if (!$supplier) Response::notFound('Supplier not found');
        $this->supplierModel->delete((int)$id);
        Response::success(null, 'Supplier deleted');
    }

    /** Single-item purchase (legacy) */
    public function purchase(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, [
            'supplier_id' => 'required',
            'product_id'  => 'required',
            'quantity'    => 'required|numeric',
            'cost'        => 'required|numeric',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $product = $this->productModel->findById((int)$data['product_id']);
        if (!$product) Response::notFound('Product not found');

        $supplier = $this->supplierModel->findById((int)$data['supplier_id']);
        if (!$supplier) Response::notFound('Supplier not found');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $this->supplierModel->createPurchase($data);
            $this->productModel->incrementQuantity((int)$data['product_id'], (int)$data['quantity']);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Response::serverError('Failed to record purchase');
        }

        Response::success([
            'product' => $this->productModel->findById((int)$data['product_id']),
        ], 'Purchase recorded and stock updated', 201);
    }

    /** List purchase invoices (like sales list) */
    public function purchaseInvoices(): void {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date'        => $this->getParam('date'),
            'month'       => $this->getParam('month'),
            'year'        => $this->getParam('year'),
        ];
        Response::success($this->supplierModel->getPurchaseInvoices($filters));
    }

    /** Get single purchase invoice detail (like sales detail) */
    public function purchaseInvoiceDetail(string $id): void {
        $invoice = $this->supplierModel->getPurchaseInvoice((int)$id);
        if (!$invoice) Response::notFound('Purchase invoice not found');
        Response::success($invoice);
    }

    /** Delete a purchase invoice and restore stock */
    public function purchaseInvoiceDelete(string $id): void {
        $invoice = $this->supplierModel->getPurchaseInvoice((int)$id);
        if (!$invoice) Response::notFound('Purchase invoice not found');

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Restore stock quantities
            foreach ($invoice['items'] as $item) {
                $this->productModel->decrementQuantity((int)$item['product_id'], (int)$item['quantity']);
            }
            $this->supplierModel->deletePurchaseInvoice((int)$id);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to delete purchase invoice');
        }

        Response::success(null, 'Purchase invoice deleted and stock restored');
    }

    /** Legacy flat purchases list */
    public function purchases(): void {
        $filters = [
            'supplier_id' => $this->getParam('supplier_id'),
            'date_from'   => $this->getParam('date_from'),
            'date_to'     => $this->getParam('date_to'),
        ];
        Response::success($this->supplierModel->getPurchases($filters));
    }

    /** Bulk purchase — creates a purchase invoice + items */
    public function purchaseBulk(): void {
        $data = $this->getBody();

        if (empty($data['supplier_id'])) {
            Response::error('supplier_id is required', 422);
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            Response::error('items array is required', 422);
        }

        $supplier = $this->supplierModel->findById((int)$data['supplier_id']);
        if (!$supplier) Response::notFound('Supplier not found');

        $replaceInvoiceId = isset($data['replace_invoice_id']) ? (int) $data['replace_invoice_id'] : 0;
        $existingInvoice = null;
        if ($replaceInvoiceId > 0) {
            $existingInvoice = $this->supplierModel->getPurchaseInvoice($replaceInvoiceId);
            if (!$existingInvoice) Response::notFound('Original invoice not found for replacement');
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // Calculate total
            $grandTotal = 0;
            foreach ($data['items'] as $item) {
                $grandTotal += (float)$item['cost'] * (int)$item['quantity'];
            }

            if ($replaceInvoiceId > 0) {
                // Revert old stock
                foreach ($existingInvoice['items'] as $oldItem) {
                    $this->productModel->decrementQuantity((int)$oldItem['product_id'], (int)$oldItem['quantity']);
                }
                // Delete old items
                $this->supplierModel->deletePurchaseInvoiceItems($replaceInvoiceId);
                // Update header
                $this->supplierModel->updatePurchaseInvoiceTotals($replaceInvoiceId, [
                    'total' => $grandTotal,
                    'items_count' => count($data['items']),
                    'notes' => $data['notes'] ?? null
                ]);
                $invoiceId = $replaceInvoiceId;
            } else {
                $invoiceId = $this->supplierModel->createPurchaseInvoice([
                    'supplier_id' => (int)$data['supplier_id'],
                    'total'       => $grandTotal,
                    'items_count' => count($data['items']),
                    'notes'       => $data['notes'] ?? null,
                ]);
            }

            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || !isset($item['cost'])) {
                    $db->rollBack();
                    Response::error('Each item needs product_id, quantity, and cost', 422);
                }

                $product = $this->productModel->findById((int)$item['product_id']);
                if (!$product) {
                    $db->rollBack();
                    Response::notFound("Product ID {$item['product_id']} not found");
                }

                $this->supplierModel->createPurchase([
                    'purchase_invoice_id' => $invoiceId,
                    'supplier_id'         => (int)$data['supplier_id'],
                    'product_id'          => (int)$item['product_id'],
                    'quantity'            => (int)$item['quantity'],
                    'cost'                => (float)$item['cost'],
                ]);
                $this->productModel->incrementQuantity((int)$item['product_id'], (int)$item['quantity']);

                // Update product cost price if provided
                if (!empty($item['update_cost'])) {
                    $db->prepare('UPDATE products SET cost = ? WHERE id = ?')
                       ->execute([(float)$item['cost'], (int)$item['product_id']]);
                }
            }
            // تسجيل قيد مدين في كشف حساب المورد (شراء آجل)
            $isCreditPurchase = ($data['payment_type'] ?? '') === 'credit';
            if ($isCreditPurchase && $replaceInvoiceId === 0) {
                $auth = $_SERVER['AUTH_USER'];
                $deposit = (float)($data['deposit'] ?? 0);

                // قيد مدين بقيمة الفاتورة الكاملة
                $this->supplierModel->addLedgerEntry([
                    'supplier_id'         => (int)$data['supplier_id'],
                    'type'                => 'debit',
                    'amount'              => $grandTotal,
                    'description'         => "فاتورة شراء #{$invoiceId}" . ($deposit > 0 ? " (عربون {$deposit})" : ''),
                    'purchase_invoice_id' => $invoiceId,
                    'created_by'          => $auth['id'],
                ]);

                // قيد دائن للعربون إذا كان موجوداً
                if ($deposit > 0) {
                    $this->supplierModel->addLedgerEntry([
                        'supplier_id'         => (int)$data['supplier_id'],
                        'type'                => 'credit',
                        'amount'              => $deposit,
                        'description'         => "عربون فاتورة شراء #{$invoiceId}",
                        'purchase_invoice_id' => $invoiceId,
                        'created_by'          => $auth['id'],
                    ]);
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('Failed to record bulk purchase');
        }

        Response::success([
            'invoice_id'      => $invoiceId,
            'items_processed' => count($data['items']),
        ], $replaceInvoiceId > 0 ? 'Purchase invoice updated' : 'Bulk purchase recorded', $replaceInvoiceId > 0 ? 200 : 201);
    }

    /**
     * POST /api/suppliers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب المورد
     */
    public function addPayment(string $id): void {
        $sid  = (int)$id;
        $data = $this->getBody();

        $supplier = $this->supplierModel->findById($sid);
        if (!$supplier) {
            Response::notFound('المورد غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
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
        Response::success($this->supplierModel->getLedger($sid), 'تم تسجيل الدفعة');
    }

    /**
     * PUT /api/suppliers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId): void {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        $entry = $this->supplierModel->getLedgerEntry($eid);
        if (!$entry) {
            Response::notFound('القيد غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
        }
        $type = $data['type'] ?? $entry['type'];
        if (!in_array($type, ['debit', 'credit'])) {
            Response::error('نوع القيد غير صحيح', 422);
        }

        $this->supplierModel->updateLedgerEntry($eid, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        // إعادة كشف الحساب المحدَّث
        Response::success($this->supplierModel->getLedger((int)$entry['supplier_id']), 'تم تحديث القيد');
    }
}

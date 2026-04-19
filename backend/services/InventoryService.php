<?php

/**
 * InventoryService — منطق المخزون المشترك.
 *
 * يُوحّد عمليات زيادة/خصم المخزون المستخدمة في SaleController
 * و SupplierController لتجنب التكرار.
 */
class InventoryService
{
    private Product  $productModel;
    private Supplier $supplierModel;

    public function __construct()
    {
        $this->productModel  = new Product();
        $this->supplierModel = new Supplier();
    }

    // ── Bulk purchase (from supplier) ────────────────────────

    /**
     * تنفيذ عملية شراء بالجملة من مورد.
     *
     * @return array ['ok' => true, 'invoice_id' => int, 'items_processed' => int]
     *               أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function processBulkPurchase(array $data, array $authUser): array
    {
        if (empty($data['supplier_id'])) {
            return ['ok' => false, 'error' => 'supplier_id is required', 'code' => 422];
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return ['ok' => false, 'error' => 'items array is required', 'code' => 422];
        }

        $supplier = $this->supplierModel->findById((int) $data['supplier_id']);
        if (!$supplier) {
            return ['ok' => false, 'error' => 'Supplier not found', 'code' => 404];
        }

        $replaceInvoiceId = isset($data['replace_invoice_id']) ? (int) $data['replace_invoice_id'] : 0;
        $existingInvoice = null;
        if ($replaceInvoiceId > 0) {
            $existingInvoice = $this->supplierModel->getPurchaseInvoice($replaceInvoiceId);
            if (!$existingInvoice) {
                return ['ok' => false, 'error' => 'Original invoice not found for replacement', 'code' => 404];
            }
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $grandTotal = 0;
            foreach ($data['items'] as $item) {
                $grandTotal += (float) $item['cost'] * (int) $item['quantity'];
            }

            if ($replaceInvoiceId > 0) {
                foreach ($existingInvoice['items'] as $oldItem) {
                    $this->productModel->decrementQuantity((int) $oldItem['product_id'], (int) $oldItem['quantity']);
                }
                $this->supplierModel->deletePurchaseInvoiceItems($replaceInvoiceId);
                $this->supplierModel->updatePurchaseInvoiceTotals($replaceInvoiceId, [
                    'total'       => $grandTotal,
                    'items_count' => count($data['items']),
                    'notes'       => $data['notes'] ?? null,
                ]);
                $invoiceId = $replaceInvoiceId;
            } else {
                $invoiceId = $this->supplierModel->createPurchaseInvoice([
                    'supplier_id' => (int) $data['supplier_id'],
                    'total'       => $grandTotal,
                    'items_count' => count($data['items']),
                    'notes'       => $data['notes'] ?? null,
                ]);
            }

            foreach ($data['items'] as $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || !isset($item['cost'])) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'Each item needs product_id, quantity, and cost', 'code' => 422];
                }

                $product = $this->productModel->findById((int) $item['product_id']);
                if (!$product) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => "Product ID {$item['product_id']} not found", 'code' => 404];
                }

                $this->supplierModel->createPurchase([
                    'purchase_invoice_id' => $invoiceId,
                    'supplier_id'         => (int) $data['supplier_id'],
                    'product_id'          => (int) $item['product_id'],
                    'quantity'            => (int) $item['quantity'],
                    'cost'                => (float) $item['cost'],
                ]);
                $this->productModel->incrementQuantity((int) $item['product_id'], (int) $item['quantity']);

                if (!empty($item['update_cost'])) {
                    $db->prepare('UPDATE products SET cost = ? WHERE id = ?')
                       ->execute([(float) $item['cost'], (int) $item['product_id']]);
                }
            }

            // تسجيل قيود كشف حساب المورد
            $isCreditPurchase = ($data['payment_type'] ?? '') === 'credit';
            if ($isCreditPurchase && $replaceInvoiceId === 0) {
                $this->recordSupplierLedger(
                    (int) $data['supplier_id'],
                    $invoiceId,
                    $grandTotal,
                    (float) ($data['deposit'] ?? 0),
                    $authUser
                );
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل عملية شراء بالجملة', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to record bulk purchase', 'code' => 500];
        }

        return [
            'ok'              => true,
            'invoice_id'      => $invoiceId,
            'items_processed' => count($data['items']),
            'is_update'       => $replaceInvoiceId > 0,
        ];
    }

    // ── Delete purchase invoice ──────────────────────────────

    /**
     * حذف فاتورة شراء مع إرجاع الكميات من المخزون.
     */
    public function deletePurchaseInvoice(int $id): array
    {
        $invoice = $this->supplierModel->getPurchaseInvoice($id);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Purchase invoice not found', 'code' => 404];
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            foreach ($invoice['items'] as $item) {
                $this->productModel->decrementQuantity((int) $item['product_id'], (int) $item['quantity']);
            }
            $this->supplierModel->deletePurchaseInvoice($id);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل حذف فاتورة الشراء', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to delete purchase invoice', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Supplier ledger helper ───────────────────────────────

    private function recordSupplierLedger(int $supplierId, int $invoiceId, float $grandTotal, float $deposit, array $authUser): void
    {
        $this->supplierModel->addLedgerEntry([
            'supplier_id'         => $supplierId,
            'type'                => 'debit',
            'amount'              => $grandTotal,
            'description'         => "فاتورة شراء #{$invoiceId}" . ($deposit > 0 ? " (عربون {$deposit})" : ''),
            'purchase_invoice_id' => $invoiceId,
            'created_by'          => $authUser['id'],
        ]);

        if ($deposit > 0) {
            $this->supplierModel->addLedgerEntry([
                'supplier_id'         => $supplierId,
                'type'                => 'credit',
                'amount'              => $deposit,
                'description'         => "عربون فاتورة شراء #{$invoiceId}",
                'purchase_invoice_id' => $invoiceId,
                'created_by'          => $authUser['id'],
            ]);
        }
    }
}

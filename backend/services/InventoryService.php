<?php

namespace App\Services;

use App\Config\Database;
use App\Controllers\SaleController;
use App\Controllers\SupplierController;
use App\Helpers\Logger;
use App\Models\Product;
use App\Repositories\SupplierRepository;
use Throwable;
use App\Contracts\InventoryServiceInterface;

/**
 * InventoryService — منطق المخزون المشترك.
 *
 * يُوحّد عمليات زيادة/خصم المخزون المستخدمة في SaleController
 * و SupplierController لتجنب التكرار.
 */
class InventoryService implements InventoryServiceInterface
{
    private Product  $productModel;
    private SupplierRepository $supplierRepo;

    public function __construct(Product $productModel, SupplierRepository $supplierRepo)
    {
        $this->productModel = $productModel;
        $this->supplierRepo = $supplierRepo;
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

        $supplier = $this->supplierRepo->findById((int) $data['supplier_id']);
        if (!$supplier) {
            return ['ok' => false, 'error' => 'Supplier not found', 'code' => 404];
        }

        $replaceInvoiceId = isset($data['replace_invoice_id']) ? (int) $data['replace_invoice_id'] : 0;
        $existingInvoice = null;
        if ($replaceInvoiceId > 0) {
            $existingInvoice = $this->supplierRepo->getPurchaseInvoice($replaceInvoiceId);
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
                $this->supplierRepo->deletePurchaseInvoiceItems($replaceInvoiceId);
                $this->supplierRepo->updatePurchaseInvoiceTotals($replaceInvoiceId, [
                    'total'          => $grandTotal,
                    'items_count'    => count($data['items']),
                    'notes'          => $data['notes'] ?? null,
                    'driver_name'    => $data['driver_name'] ?? null,
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
                ]);
                $invoiceId = $replaceInvoiceId;
            } else {
                $invoiceId = $this->supplierRepo->createPurchaseInvoice([
                    'supplier_id'    => (int) $data['supplier_id'],
                    'total'          => $grandTotal,
                    'items_count'    => count($data['items']),
                    'notes'          => $data['notes'] ?? null,
                    'driver_name'    => $data['driver_name'] ?? null,
                    'vehicle_number' => $data['vehicle_number'] ?? null,
                    'delivery_date'  => $data['delivery_date'] ?? null,
                    'delivery_notes' => $data['delivery_notes'] ?? null,
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

                $this->supplierRepo->createPurchase([
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
            $paymentType = $data['payment_type'] ?? 'cash';
            if ($replaceInvoiceId > 0) {
                // Delete old ledger entries linked to this invoice before writing the updated ones
                $db->prepare('DELETE FROM supplier_ledger WHERE purchase_invoice_id = ?')->execute([$invoiceId]);
            }

            if ($paymentType === 'credit') {
                $this->recordSupplierLedger(
                    (int) $data['supplier_id'],
                    $invoiceId,
                    $grandTotal,
                    (float) ($data['deposit'] ?? 0),
                    $authUser,
                    'credit'
                );
            } elseif ($paymentType === 'cash') {
                $this->recordSupplierLedger(
                    (int) $data['supplier_id'],
                    $invoiceId,
                    $grandTotal,
                    $grandTotal,
                    $authUser,
                    'cash'
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
        $invoice = $this->supplierRepo->getPurchaseInvoice($id);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Purchase invoice not found', 'code' => 404];
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            foreach ($invoice['items'] as $item) {
                $this->productModel->decrementQuantity((int) $item['product_id'], (int) $item['quantity']);
            }
            
            // Delete related supplier ledger entries
            $db->prepare('DELETE FROM supplier_ledger WHERE purchase_invoice_id = ?')->execute([$id]);

            $this->supplierRepo->deletePurchaseInvoice($id);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل حذف فاتورة الشراء', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to delete purchase invoice', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Supplier ledger helper ───────────────────────────────

    private function recordSupplierLedger(int $supplierId, int $invoiceId, float $grandTotal, float $deposit, array $authUser, string $paymentType = 'credit'): void
    {
        $desc = "فاتورة شراء #{$invoiceId}";
        if ($paymentType === 'cash') {
            $desc .= " (نقدي)";
        } elseif ($deposit > 0) {
            $desc .= " (عربون {$deposit})";
        }

        $this->supplierRepo->addLedgerEntry([
            'supplier_id'         => $supplierId,
            'type'                => 'debit',
            'amount'              => $grandTotal,
            'description'         => $desc,
            'purchase_invoice_id' => $invoiceId,
            'created_by'          => $authUser['id'],
        ]);

        if ($paymentType === 'cash') {
            $this->supplierRepo->addLedgerEntry([
                'supplier_id'         => $supplierId,
                'type'                => 'credit',
                'amount'              => $grandTotal,
                'description'         => "سداد نقدي لفاتورة شراء #{$invoiceId}",
                'purchase_invoice_id' => $invoiceId,
                'created_by'          => $authUser['id'],
            ]);
        } elseif ($deposit > 0) {
            $this->supplierRepo->addLedgerEntry([
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

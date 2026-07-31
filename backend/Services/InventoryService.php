<?php

namespace App\Services;

use App\Controllers\SaleController;
use App\Controllers\SupplierController;
use App\Helpers\Logger;
use App\Models\Product;
use App\Repositories\SupplierRepository;
use PDO;
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
    private PDO $db;

    public function __construct(Product $productModel, SupplierRepository $supplierRepo, PDO $db)
    {
        $this->productModel = $productModel;
        $this->supplierRepo = $supplierRepo;
        $this->db = $db;
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
        if (
            !isset($data['supplier_id'])
            || filter_var($data['supplier_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            return ['ok' => false, 'error' => 'supplier_id is required', 'code' => 422];
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return ['ok' => false, 'error' => 'items array is required', 'code' => 422];
        }
        if (count($data['items']) > 500) {
            return ['ok' => false, 'error' => 'A purchase cannot contain more than 500 items', 'code' => 422];
        }

        $seenProductIds = [];
        $grandTotal = 0.0;
        foreach ($data['items'] as $item) {
            if (
                !is_array($item)
                || !isset($item['product_id'], $item['quantity'], $item['cost'])
                || filter_var($item['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || !is_numeric($item['quantity'])
                || !is_finite((float) $item['quantity'])
                || (float) $item['quantity'] <= 0
                || !is_numeric($item['cost'])
                || !is_finite((float) $item['cost'])
                || (float) $item['cost'] < 0
            ) {
                return ['ok' => false, 'error' => 'Invalid purchase item', 'code' => 422];
            }
            $productId = (int) $item['product_id'];
            if (isset($seenProductIds[$productId])) {
                return ['ok' => false, 'error' => 'Duplicate products are not allowed', 'code' => 422];
            }
            $seenProductIds[$productId] = true;
            $grandTotal += (float) $item['cost'] * (float) $item['quantity'];
        }

        $paymentType = $data['payment_type'] ?? 'cash';
        if (!in_array($paymentType, ['cash', 'credit'], true)) {
            return ['ok' => false, 'error' => 'Invalid payment type', 'code' => 422];
        }
        $deposit = $data['deposit'] ?? 0;
        if (!is_numeric($deposit) || !is_finite((float) $deposit) || (float) $deposit < 0) {
            return ['ok' => false, 'error' => 'Invalid deposit', 'code' => 422];
        }
        if ((float) $deposit > $grandTotal) {
            return ['ok' => false, 'error' => 'Deposit cannot exceed purchase total', 'code' => 422];
        }

        $supplier = $this->supplierRepo->findById((int) $data['supplier_id']);
        if (!$supplier) {
            return ['ok' => false, 'error' => 'Supplier not found', 'code' => 404];
        }

        $replaceInvoiceId = isset($data['replace_invoice_id']) ? (int) $data['replace_invoice_id'] : 0;
        $existingInvoice = null;
        if ($this->db->inTransaction()) {
            return ['ok' => false, 'error' => 'Inventory transaction already active', 'code' => 409];
        }

        try {
            $this->db->beginTransaction();

            if ($replaceInvoiceId > 0) {
                $existingInvoice = $this->supplierRepo->getPurchaseInvoiceHeaderForUpdate($replaceInvoiceId);
                if (!$existingInvoice) {
                    throw new \RuntimeException('Original invoice not found for replacement');
                }
                $existingInvoice['items'] = $this->supplierRepo->getPurchaseInvoiceItems($replaceInvoiceId);
            }

            if ($replaceInvoiceId > 0) {
                foreach ($existingInvoice['items'] as $oldItem) {
                    $this->productModel->decrementQuantity((int) $oldItem['product_id'], (float) $oldItem['quantity']);
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
                $product = $this->productModel->findById((int) $item['product_id']);
                if (!$product) {
                    throw new \RuntimeException("Product ID {$item['product_id']} not found");
                }

                $this->supplierRepo->createPurchase([
                    'purchase_invoice_id' => $invoiceId,
                    'supplier_id'         => (int) $data['supplier_id'],
                    'product_id'          => (int) $item['product_id'],
                    'quantity'            => (float) $item['quantity'],
                    'cost'                => (float) $item['cost'],
                ]);
                $this->productModel->incrementQuantity((int) $item['product_id'], (float) $item['quantity']);

                if (!empty($item['update_cost'])) {
                    $this->db->prepare('UPDATE products SET cost = ? WHERE id = ? AND branch_id = ?')
                       ->execute([
                           (float) $item['cost'],
                           (int) $item['product_id'],
                           \App\Services\AuthService::getGlobalBranchId(),
                       ]);
                }
            }

            // تسجيل قيود كشف حساب المورد
            if ($replaceInvoiceId > 0) {
                // Delete old ledger entries linked to this invoice before writing the updated ones
                $this->db->prepare('DELETE FROM supplier_ledger WHERE purchase_invoice_id = ?')->execute([$invoiceId]);
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

            $this->db->commit();
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Original invoice not found for replacement') {
                return ['ok' => false, 'error' => 'Original invoice not found for replacement', 'code' => 404];
            }
            if (str_starts_with($e->getMessage(), 'Product ID ')) {
                return ['ok' => false, 'error' => $e->getMessage(), 'code' => 404];
            }
            if ($e->getMessage() === 'Insufficient stock or out-of-scope product') {
                return ['ok' => false, 'error' => 'Insufficient stock to replace purchase', 'code' => 409];
            }
            if ($e->getMessage() === 'Out-of-scope product') {
                return ['ok' => false, 'error' => 'Product changed concurrently', 'code' => 409];
            }
            Logger::error('Bulk purchase transaction failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to record bulk purchase', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
        if ($this->db->inTransaction()) {
            return ['ok' => false, 'error' => 'Inventory transaction already active', 'code' => 409];
        }

        try {
            $this->db->beginTransaction();
            $invoice = $this->supplierRepo->getPurchaseInvoiceHeaderForUpdate($id);
            if (!$invoice) {
                throw new \RuntimeException('Purchase invoice not found');
            }
            $invoice['items'] = $this->supplierRepo->getPurchaseInvoiceItems($id);

            foreach ($invoice['items'] as $item) {
                $this->productModel->decrementQuantity((int) $item['product_id'], (float) $item['quantity']);
            }
            
            // Delete related supplier ledger entries
            $this->db->prepare('DELETE FROM supplier_ledger WHERE purchase_invoice_id = ?')->execute([$id]);

            if ($this->supplierRepo->deletePurchaseInvoice($id) !== 1) {
                throw new \RuntimeException('Purchase invoice changed concurrently');
            }
            $this->db->commit();
        } catch (\RuntimeException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getMessage() === 'Purchase invoice not found') {
                return ['ok' => false, 'error' => 'Purchase invoice not found', 'code' => 404];
            }
            if ($e->getMessage() === 'Insufficient stock or out-of-scope product') {
                return ['ok' => false, 'error' => 'Insufficient stock to delete purchase', 'code' => 409];
            }
            if (in_array($e->getMessage(), ['Purchase invoice changed concurrently', 'Out-of-scope product'], true)) {
                return ['ok' => false, 'error' => 'Purchase invoice changed concurrently', 'code' => 409];
            }
            Logger::error('Purchase deletion failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to delete purchase invoice', 'code' => 500];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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

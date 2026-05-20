<?php

namespace App\Services;

use App\Config\Database;
use App\Controllers\SaleController;
use App\Helpers\Logger;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use PDO;
use Throwable;
use App\Contracts\SaleServiceInterface;

/**
 * SaleService — منطق الأعمال لعمليات البيع.
 *
 * يستخرج Business Logic من SaleController ليبقى الكونترولر
 * مسؤولاً فقط عن استقبال HTTP requests وإرجاع responses.
 */
class SaleService implements SaleServiceInterface
{
    private Invoice  $invoiceModel;
    private Product  $productModel;
    private Customer $customerModel;
    private PDO      $db;

    public function __construct(Invoice $invoiceModel, Product $productModel, Customer $customerModel)
    {
        $this->invoiceModel  = $invoiceModel;
        $this->productModel  = $productModel;
        $this->customerModel = $customerModel;
        $this->db            = Database::getInstance();
    }

    // ── Settings helper ───────────────────────────────────────
    public function getSettings(): array
    {
        // استخدام Cache لتجنب استعلام DB في كل عملية بيع
        $cached = \App\Helpers\Cache::get('settings_all');
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        try {
            $rows = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
            $s = [];
            foreach ($rows as $r) { $s[$r['key']] = $r['value']; }

            // حفظ في الكاش لمدة 5 دقائق (300 ثانية)
            \App\Helpers\Cache::set('settings_all', $s, 300);

            return $s;
        } catch (Throwable $e) {
            return ['tax_enabled' => '0', 'tax_rate' => '15'];
        }
    }

    // ── Enrich & validate sale items ──────────────────────────

    /**
     * التحقق من المنتجات وإثراء البيانات بمعلومات من قاعدة البيانات.
     *
     * @param  array  $items  بنود السلة الخام من العميل
     * @return array  ['ok' => true, 'items' => [...]] أو ['ok' => false, 'error' => '...', 'code' => int]
     */
    public function enrichItems(array $items): array
    {
        $enriched = [];
        foreach ($items as $item) {
            if (empty($item['product_id']) || empty($item['quantity'])) {
                return ['ok' => false, 'error' => 'Invalid item data', 'code' => 400];
            }
            $product = $this->productModel->findById((int) $item['product_id']);
            if (!$product) {
                return ['ok' => false, 'error' => "Product ID {$item['product_id']} not found", 'code' => 400];
            }
            // Use float for quantity to support sell-by-weight products (e.g. 0.5 kg)
            $enriched[] = [
                'product_id' => $product['id'],
                'quantity'   => (float) $item['quantity'],
                'price'      => isset($item['price']) ? (float) $item['price'] : (float) $product['price'],
                'unit_cost'  => (float) ($product['cost'] ?? 0),
                'product'    => $product,
            ];
        }
        return ['ok' => true, 'items' => $enriched];
    }

    // ── Calculate totals ─────────────────────────────────────

    /**
     * حساب الإجماليات (المجموع، الخصم، الضريبة، الإجمالي النهائي).
     *
     * @param  array  $enrichedItems  البنود المُثرَاة
     * @param  float  $discount       الخصم اليدوي
     * @param  array  $data           بيانات الطلب (amount_paid, customer_id, deposit ...)
     * @return array  totals hash
     */
    public function calculateTotals(array $enrichedItems, float $discount, array $data): array
    {
        $settings   = $this->getSettings();
        $taxEnabled = (bool) (int) ($settings['tax_enabled'] ?? 0);
        $taxRate    = (float) ($settings['tax_rate'] ?? 15) / 100;

        $subtotal   = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $enrichedItems));
        $taxable    = $subtotal - $discount;
        $tax        = $taxEnabled ? round($taxable * $taxRate, 2) : 0;
        $total      = round($taxable + $tax, 2);
        
        $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $total;
        
        $changeDue  = max(0, round($amountPaid - $total, 2));
        $amountDue  = max(0, round($total - $amountPaid, 2));

        $customerId = isset($data['customer_id']) && $data['customer_id'] > 0
            ? (int) $data['customer_id']
            : null;
        $isCreditSale = ($data['payment_method'] ?? '') === 'credit';
        $deposit      = $isCreditSale ? $amountPaid : 0;

        return [
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'tax'            => $tax,
            'total'          => $total,
            'amount_paid'    => $amountPaid,
            'change_due'     => $changeDue,
            'amount_due'     => $amountDue,
            'customer_id'    => $customerId,
            'is_credit_sale' => $isCreditSale,
            'deposit'        => $deposit,
        ];
    }

    // ── Process sale (main transaction) ──────────────────────

    /**
     * تنفيذ عملية البيع الكاملة داخل transaction.
     *
     * @return array ['ok' => true, 'invoice_id' => int] أو ['ok' => false, 'error' => string]
     */
    public function processSale(array $enrichedItems, array $totals, array $data, array $authUser): array
    {
        $replaceInvoiceId = isset($data['invoice_id']) ? (int) $data['invoice_id'] : 0;
        $existingInvoice  = null;

        if ($replaceInvoiceId > 0) {
            $existingInvoice = $this->invoiceModel->findById($replaceInvoiceId);
            if (!$existingInvoice) {
                return ['ok' => false, 'error' => 'Invoice not found', 'code' => 404];
            }
        }

        $this->db->beginTransaction();
        try {
            $customerId = $totals['customer_id'];

            if ($replaceInvoiceId > 0) {
                // مرتجع / إعادة فوترة
                $inventoryEvent = new \App\Models\InventoryEvent($this->db);
                foreach ($existingInvoice['items'] as $old) {
                    $this->productModel->incrementQuantity((int) $old['product_id'], (float) $old['quantity']);
                    $updatedProduct = $this->productModel->findById((int) $old['product_id']);
                    $inventoryEvent->record((int) $old['product_id'], 'delete', (int)$updatedProduct['quantity'], (int)$old['quantity']);
                }
                
                // حذف قيود كشف الحساب القديمة الخاصة بهذه الفاتورة
                $this->db->prepare('DELETE FROM customer_ledger WHERE invoice_id = ?')->execute([$replaceInvoiceId]);
                
                $this->invoiceModel->deleteItemsByInvoiceId($replaceInvoiceId);

                $this->invoiceModel->updateTotals($replaceInvoiceId, [
                    'customer_id'    => $customerId,
                    'subtotal'       => $totals['subtotal'],
                    'discount'       => $totals['discount'],
                    'tax'            => $totals['tax'],
                    'total'          => $totals['total'],
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $totals['amount_paid'],
                    'change_due'     => max(0, $totals['change_due']),
                    'amount_due'     => $totals['amount_due'],
                    'status'         => $data['status'] ?? 'completed',
                ]);
                $invoiceId = $replaceInvoiceId;
                
                // تسجيل القيود الجديدة
                if ($customerId !== null) {
                    $this->recordCustomerLedger($customerId, $invoiceId, $totals, $authUser, $data['status'] ?? 'completed', $data['payment_method'] ?? 'cash');
                }
            } else {
                // إنشاء عميل جديد إذا لزم
                if ($customerId === null && !empty($data['new_customer']['name'])) {
                    $nc = $data['new_customer'];
                    $customerId = $this->customerModel->create([
                        'name'            => trim($nc['name']),
                        'phone'           => $nc['phone'] ?? null,
                        'address'         => $nc['address'] ?? null,
                        'initial_balance' => 0,
                    ]);
                }

                $invoiceId = $this->invoiceModel->create([
                    'user_id'        => $authUser['id'],
                    'customer_id'    => $customerId,
                    'subtotal'       => $totals['subtotal'],
                    'discount'       => $totals['discount'],
                    'tax'            => $totals['tax'],
                    'total'          => $totals['total'],
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $totals['amount_paid'],
                    'change_due'     => max(0, $totals['change_due']),
                    'amount_due'     => $totals['amount_due'],
                    'status'         => $data['status'] ?? 'completed',
                ]);

                // تسجيل قيود كشف حساب العميل
                if ($customerId !== null) {
                    $this->recordCustomerLedger($customerId, $invoiceId, $totals, $authUser, $data['status'] ?? 'completed', $data['payment_method'] ?? 'cash');
                }
            }

            // إضافة البنود وخصم المخزون
            $inventoryEvent = new \App\Models\InventoryEvent($this->db);
            foreach ($enrichedItems as $item) {
                $this->invoiceModel->addItem($invoiceId, $item);
                $this->productModel->decrementQuantity($item['product_id'], $item['quantity']);
                $updatedProduct = $this->productModel->findById($item['product_id']);
                $inventoryEvent->record($item['product_id'], 'sale', (int)$updatedProduct['quantity'], -$item['quantity']);
            }

            $this->db->commit();

            // إضافة نقاط الولاء كـ Background Job (لا تبطئ استجابة البيع)
            if ($customerId !== null && $replaceInvoiceId === 0) {
                \App\Helpers\JobQueue::dispatch('earn_loyalty_points', [
                    'customer_id' => $customerId,
                    'invoice_id'  => $invoiceId,
                    'total'       => $totals['total'],
                ], 1); // priority=1 (أعلى من المهام العادية)
            }
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::error('فشل إنشاء عملية بيع', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to process sale', 'code' => 500];
        }

        return ['ok' => true, 'invoice_id' => $invoiceId, 'is_update' => $replaceInvoiceId > 0];
    }

    // ── Customer ledger entries ──────────────────────────────

    private function recordCustomerLedger(int $customerId, int $invoiceId, array $totals, array $authUser, string $status = 'completed', string $paymentMethod = 'cash'): void
    {
        $effectivePayment = max(0, $totals['total'] - $totals['amount_due']);
        $paymentDesc = $paymentMethod === 'credit' && $effectivePayment > 0 ? " (عربون {$effectivePayment})" : '';
        
        $statusMarker = $status === 'reserved' ? ' 🕒 (محجوزة - لم تُسلم)' : '';

        $this->customerModel->addLedgerEntry([
            'customer_id' => $customerId,
            'type'        => 'debit',
            'amount'      => $totals['total'],
            'description' => "فاتورة بيع #{$invoiceId}{$paymentDesc}{$statusMarker}",
            'invoice_id'  => $invoiceId,
            'created_by'  => $authUser['id'],
        ]);

        if ($effectivePayment > 0) {
            $desc = $paymentMethod === 'credit' ? "عربون فاتورة #{$invoiceId}" : "سداد لفاتورة #{$invoiceId}";
            $this->customerModel->addLedgerEntry([
                'customer_id' => $customerId,
                'type'        => 'credit',
                'amount'      => $effectivePayment,
                'description' => $desc,
                'invoice_id'  => $invoiceId,
                'created_by'  => $authUser['id'],
            ]);
        }
    }

    // ── Low stock check ─────────────────────────────────────

    /**
     * فحص تنبيهات المخزون المنخفض بعد البيع.
     */
    public function getLowStockAlerts(array $enrichedItems): array
    {
        if (empty($enrichedItems)) return [];
        $productIds = array_unique(array_column($enrichedItems, 'product_id'));
        return $this->productModel->getLowStockByProductIds($productIds);
    }

    // ── Delete invoice ──────────────────────────────────────

    /**
     * حذف فاتورة مع إرجاع الكميات للمخزون.
     *
     * @return array ['ok' => true] أو ['ok' => false, 'error' => string, 'code' => int]
     */
    public function deleteInvoice(int $invoiceId): array
    {
        $invoice = $this->invoiceModel->findById($invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'error' => 'Invoice not found', 'code' => 404];
        }

        $this->db->beginTransaction();
        try {
            $inventoryEvent = new \App\Models\InventoryEvent($this->db);
            foreach ($invoice['items'] as $item) {
                $this->productModel->incrementQuantity((int) $item['product_id'], (float) $item['quantity']);
                $updatedProduct = $this->productModel->findById((int) $item['product_id']);
                $inventoryEvent->record((int) $item['product_id'], 'delete', (int)$updatedProduct['quantity'], (int)$item['quantity']);
            }
            // حذف قيود كشف الحساب المرتبطة بهذه الفاتورة
            $this->db->prepare('DELETE FROM customer_ledger WHERE invoice_id = ?')->execute([$invoiceId]);
            
            // حذف الفاتورة (والعناصر المرتبطة بها تحذف تلقائياً بفضل ON DELETE CASCADE)
            $this->db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$invoiceId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::error('فشل حذف الفاتورة', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to delete invoice', 'code' => 500];
        }

        return ['ok' => true];
    }

    // ── Accessors ───────────────────────────────────────────

    public function getInvoiceModel(): Invoice
    {
        return $this->invoiceModel;
    }
}

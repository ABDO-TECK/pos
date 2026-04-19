<?php

/**
 * SaleService — منطق الأعمال لعمليات البيع.
 *
 * يستخرج Business Logic من SaleController ليبقى الكونترولر
 * مسؤولاً فقط عن استقبال HTTP requests وإرجاع responses.
 */
class SaleService
{
    private Invoice  $invoiceModel;
    private Product  $productModel;
    private Customer $customerModel;
    private PDO      $db;

    public function __construct()
    {
        $this->invoiceModel  = new Invoice();
        $this->productModel  = new Product();
        $this->customerModel = new Customer();
        $this->db            = Database::getInstance();
    }

    // ── Settings helper ───────────────────────────────────────
    public function getSettings(): array
    {
        try {
            $rows = $this->db->query('SELECT `key`, `value` FROM settings')->fetchAll();
            $s = [];
            foreach ($rows as $r) { $s[$r['key']] = $r['value']; }
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
            $enriched[] = [
                'product_id' => $product['id'],
                'quantity'   => (int) $item['quantity'],
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
        $amountPaid = (float) ($data['amount_paid'] ?? $total);
        $changeDue  = round($amountPaid - $total, 2);

        // البيع الآجل
        $customerId = isset($data['customer_id']) && $data['customer_id'] > 0
            ? (int) $data['customer_id']
            : null;
        $isCreditSale = ($data['payment_method'] ?? '') === 'credit' || $customerId !== null;
        $deposit      = $isCreditSale ? (float) ($data['deposit'] ?? 0) : 0;

        if ($isCreditSale) {
            $amountPaid = $deposit;
            $changeDue  = 0;
        }
        $amountDue = $isCreditSale ? round($total - $deposit, 2) : 0;

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
                foreach ($existingInvoice['items'] as $old) {
                    $this->productModel->incrementQuantity((int) $old['product_id'], (int) $old['quantity']);
                }
                $this->invoiceModel->deleteItemsByInvoiceId($replaceInvoiceId);
                $this->invoiceModel->updateTotals($replaceInvoiceId, [
                    'subtotal'       => $totals['subtotal'],
                    'discount'       => $totals['discount'],
                    'tax'            => $totals['tax'],
                    'total'          => $totals['total'],
                    'payment_method' => $data['payment_method'],
                    'amount_paid'    => $totals['amount_paid'],
                    'change_due'     => max(0, $totals['change_due']),
                ]);
                $invoiceId = $replaceInvoiceId;
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
                ]);

                // تسجيل قيود كشف حساب العميل
                if ($customerId !== null) {
                    $this->recordCustomerLedger($customerId, $invoiceId, $totals, $authUser);
                }
            }

            // إضافة البنود وخصم المخزون
            foreach ($enrichedItems as $item) {
                $this->invoiceModel->addItem($invoiceId, $item);
                $this->productModel->decrementQuantity($item['product_id'], $item['quantity']);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            Logger::error('فشل إنشاء عملية بيع', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Failed to process sale', 'code' => 500];
        }

        return ['ok' => true, 'invoice_id' => $invoiceId, 'is_update' => $replaceInvoiceId > 0];
    }

    // ── Customer ledger entries ──────────────────────────────

    private function recordCustomerLedger(int $customerId, int $invoiceId, array $totals, array $authUser): void
    {
        $deposit = $totals['deposit'];
        $depositDesc = $deposit > 0 ? " (عربون {$deposit})" : '';

        $this->customerModel->addLedgerEntry([
            'customer_id' => $customerId,
            'type'        => 'debit',
            'amount'      => $totals['total'],
            'description' => "فاتورة بيع #{$invoiceId}{$depositDesc}",
            'invoice_id'  => $invoiceId,
            'created_by'  => $authUser['id'],
        ]);

        if ($deposit > 0) {
            $this->customerModel->addLedgerEntry([
                'customer_id' => $customerId,
                'type'        => 'credit',
                'amount'      => $deposit,
                'description' => "عربون فاتورة #{$invoiceId}",
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
        return array_values(array_filter(
            array_map(fn($i) => $this->productModel->findById($i['product_id']), $enrichedItems),
            fn($p) => $p && $p['quantity'] <= $p['low_stock_threshold']
        ));
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
            foreach ($invoice['items'] as $item) {
                $this->productModel->incrementQuantity((int) $item['product_id'], (int) $item['quantity']);
            }
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

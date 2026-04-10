<?php

class CustomerController extends Controller {

    private Customer $model;

    public function __construct() {
        $this->model = new Customer();
    }

    /** GET /api/customers */
    public function index(): void {
        Response::success($this->model->all());
    }

    /** GET /api/customers/{id} — بيانات العميل + كشف الحساب */
    public function show(string $id): void {
        $data = $this->model->getLedger((int)$id);
        if (!$data['customer']) {
            Response::notFound('العميل غير موجود');
        }
        Response::success($data);
    }

    /** POST /api/customers */
    public function store(): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $id = $this->model->create($data);

            // إذا كان هناك رصيد مبدئي يُسجَّل في كشف الحساب تلقائياً
            // (يُستخدم فقط للعرض عبر initial_balance، لا يُضاف كقيد منفصل)
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            error_log($e->getMessage());
            Response::serverError('فشل في إضافة العميل');
        }

        Response::success($this->model->findById($id), 'تم إضافة العميل', 201);
    }

    /** PUT /api/customers/{id} */
    public function update(string $id): void {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $cid = (int)$id;
        if (!$this->model->findById($cid)) {
            Response::notFound('العميل غير موجود');
        }
        $this->model->update($cid, $data);
        Response::success($this->model->findById($cid), 'تم تحديث العميل');
    }

    /** DELETE /api/customers/{id} */
    public function destroy(string $id): void {
        $cid = (int)$id;
        if (!$this->model->findById($cid)) {
            Response::notFound('العميل غير موجود');
        }
        $this->model->delete($cid);
        Response::success(null, 'تم حذف العميل');
    }

    /**
     * POST /api/customers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب العميل
     */
    public function addPayment(string $id): void {
        $cid  = (int)$id;
        $data = $this->getBody();

        $customer = $this->model->findById($cid);
        if (!$customer) {
            Response::notFound('العميل غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $auth = $_SERVER['AUTH_USER'];
        $this->model->addLedgerEntry([
            'customer_id' => $cid,
            'type'        => 'credit',
            'amount'      => $amount,
            'description' => $data['description'] ?? 'دفعة نقدية',
            'invoice_id'  => null,
            'created_by'  => $auth['id'],
        ]);

        // إعادة كشف الحساب المحدَّث
        Response::success($this->model->getLedger($cid), 'تم تسجيل الدفعة');
    }

    /**
     * PUT /api/customers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId): void {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        $entry = $this->model->getLedgerEntry($eid);
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

        $this->model->updateLedgerEntry($eid, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        // إعادة كشف الحساب المحدَّث
        Response::success($this->model->getLedger((int)$entry['customer_id']), 'تم تحديث القيد');
    }
}

<?php

class CustomerController extends Controller {

    private Customer $model;

    public function __construct() {
        $this->model = new Customer();
    }

    /** GET /api/customers */
    public function index() {
        $filters = [];
        if ($this->getParam('search'))  $filters['search']  = $this->getParam('search');
        if ($this->getParam('page'))    $filters['page']    = $this->getParam('page');
        if ($this->getParam('limit'))   $filters['limit']   = $this->getParam('limit');

        $result = $this->model->all($filters);

        // إذا أُرجع pagination — إرسال مع metadata
        if (isset($result['pagination'])) {
            return Response::success($result['data'], 'success', 200, ['pagination' => $result['pagination']]);
        } else {
            return Response::success($result);
        }
    }

    /** GET /api/customers/{id} — بيانات العميل + كشف الحساب */
    public function show(string $id) {
        $data = $this->model->getLedger((int)$id);
        if (!$data['customer']) {
            return Response::notFound('العميل غير موجود');
        }
        return Response::success($data);
    }

    /** POST /api/customers */
    public function store() {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $id = $this->model->create($data);

            // إذا كان هناك رصيد مبدئي يُسجَّل في كشف الحساب تلقائياً
            // (يُستخدم فقط للعرض عبر initial_balance، لا يُضاف كقيد منفصل)
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            Logger::error('فشل إضافة العميل', ['error' => $e->getMessage()]);
            return Response::serverError('فشل في إضافة العميل');
        }

        return Response::success($this->model->findById($id), 'تم إضافة العميل', 201);
    }

    /** PUT /api/customers/{id} */
    public function update(string $id) {
        $data   = $this->getBody();
        $errors = $this->validate($data, ['name' => 'required']);
        if ($errors) return Response::error('Validation failed', 422, $errors);

        $cid = (int)$id;
        if (!$this->model->findById($cid)) {
            return Response::notFound('العميل غير موجود');
        }
        $this->model->update($cid, $data);
        return Response::success($this->model->findById($cid), 'تم تحديث العميل');
    }

    /** DELETE /api/customers/{id} */
    public function destroy(string $id) {
        $cid = (int)$id;
        if (!$this->model->findById($cid)) {
            return Response::notFound('العميل غير موجود');
        }
        $this->model->delete($cid);
        return Response::success(null, 'تم حذف العميل');
    }

    /**
     * POST /api/customers/{id}/payment
     * body: { amount: float, description?: string }
     * تسجيل دفعة (قيد دائن) في كشف حساب العميل
     */
    public function addPayment(string $id) {
        $cid  = (int)$id;
        $data = $this->getBody();

        $customer = $this->model->findById($cid);
        if (!$customer) {
            return Response::notFound('العميل غير موجود');
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return Response::error('يجب أن يكون المبلغ أكبر من صفر', 422);
        }

        $type = $data['type'] === 'debit' ? 'debit' : 'credit';

        $auth = $_SERVER['AUTH_USER'];
        $this->model->addLedgerEntry([
            'customer_id' => $cid,
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? 'دفعة نقدية',
            'invoice_id'  => null,
            'created_by'  => $auth['id'],
        ]);

        // إعادة كشف الحساب المحدَّث
        return Response::success($this->model->getLedger($cid), 'تم تسجيل الدفعة');
    }

    /**
     * PUT /api/customers/ledger/{entryId}
     * body: { type: 'debit'|'credit', amount: float, description?: string }
     */
    public function updateLedgerEntry(string $entryId) {
        $eid  = (int)$entryId;
        $data = $this->getBody();

        $entry = $this->model->getLedgerEntry($eid);
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

        $this->model->updateLedgerEntry($eid, [
            'type'        => $type,
            'amount'      => $amount,
            'description' => $data['description'] ?? $entry['description'],
        ]);

        // إعادة كشف الحساب المحدَّث
        return Response::success($this->model->getLedger((int)$entry['customer_id']), 'تم تحديث القيد');
    }
}



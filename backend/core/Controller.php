<?php

namespace App\Core;


abstract class Controller {

    protected function getBody(): array {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function getParam(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    /**
     * التحقق من صحة البيانات — يدعم القواعد التالية:
     *  required    — الحقل مطلوب (لا يقبل فارغ أو null)
     *  min:N       — الحد الأدنى لعدد الأحرف
     *  max:N       — الحد الأقصى لعدد الأحرف
     *  numeric     — قيمة رقمية
     *  integer     — قيمة عدد صحيح
     *  email       — بريد إلكتروني صالح
     *  in:a,b,c    — يجب أن يكون ضمن قائمة محددة
     *  array       — يجب أن يكون مصفوفة
     *  min_value:N — الحد الأدنى للقيمة الرقمية
     *  max_value:N — الحد الأقصى للقيمة الرقمية
     *  string      — يجب أن يكون نص
     *  date        — تاريخ صالح Y-m-d
     *
     * @param array $data    البيانات المُراد التحقق منها
     * @param array $rules   قواعد بصيغة ['field' => 'required|numeric|min:1']
     * @return array         مصفوفة أخطاء (فارغة = بيانات صحيحة)
     */
    protected function validate(array $data, array $rules): array {
        $errors = Validator::validate($data, $rules);
        if (!empty($errors)) {
            throw new ValidationException($errors, 'فشل التحقق من صحة البيانات');
        }
        
        return $errors;
    }


    /**
     * استخراج معاملات الترقيم (Pagination) من الطلب مع قيم افتراضية موحدة.
     *
     * @param int $defaultLimit الحد الافتراضي لعدد النتائج (20)
     * @param int $maxLimit     الحد الأقصى المسموح (500)
     * @return array{page: int|null, limit: int|null}
     */
    protected function getPaginationParams(int $defaultLimit = 20, int $maxLimit = 500): array
    {
        $page  = $this->getParam('page');
        $limit = $this->getParam('limit');

        // إرجاع null للسماح للموديلات بجلب جميع البيانات (مطلوب للـ Offline POS)
        if ($page === null && $limit === null) {
            return ['page' => null, 'limit' => null];
        }

        return [
            'page'  => max(1, (int) ($page ?? 1)),
            'limit' => max(1, min($maxLimit, (int) ($limit ?? $defaultLimit))),
        ];
    }

    /**
     * تنفيذ عملية داخل Database Transaction.
     * إذا نجحت الدالة يُعمل commit، وإذا فشلت يُعمل rollback.
     *
     * @param callable $callback الدالة التي تحتوي على عمليات قاعدة البيانات
     * @return mixed نتيجة الدالة
     * @throws \Throwable يُعيد رمي الاستثناء بعد الـ rollback
     */
    protected function withTransaction(callable $callback): mixed
    {
        $db = \App\Config\Database::getInstance();
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    protected function sanitize(mixed $value): string {
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
    }
}

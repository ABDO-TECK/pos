<?php

/**
 * الفئة الأساسية (Base Class) لطلبات الـ DTO (Data Transfer Objects).
 * تفصل منطق الـ Validation عن الكنترولر وتعزز التنظيم.
 */
abstract class FormRequest
{
    private array $data;
    private array $validatedData = [];

    /**
     * @param array $data بيانات الطلب الواردة عادة عبر getBody()
     */
    public function __construct(array $data)
    {
        $this->data = $data;
        $this->validate();
    }

    /**
     * تحديد القواعد كما في مصفوفة قواعد Controller::validate.
     */
    abstract public function rules(): array;

    /**
     * تنفيذ التحقق ورمي استثناء في حالة الفشل.
     */
    private function validate(): void
    {
        // استدعاء التحقق الثابت (يمكننا لاحقًا نقل محرك التحقق من كلاس Controller إلى كلاس Validator مستقل)
        // حالياً سنستخدم نسخة محمولة من دالة التحقق
        $rules = $this->rules();
        $errors = $this->runValidation($this->data, $rules);

        if (!empty($errors)) {
            throw new ValidationException($errors, 'فشل التحقق من صحة البيانات');
        }

        // حفظ البيانات الصالحة
        foreach (array_keys($rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $this->validatedData[$field] = $this->data[$field];
            }
        }
    }

    /**
     * إرجاع البيانات السليمة المستخرجة فقط.
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * محرك التحقق المعزول. مطابق للموجود في Controller.
     */
    private function runValidation(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $ruleList = explode('|', $rule);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $r) {
                if ($r === 'required' && (empty($value) && $value !== 0 && $value !== '0' && $value !== 0.0)) {
                    $errors[$field][] = "حقل {$field} مطلوب.";
                    break;
                }
                if ($value === null || $value === '') continue;

                if (str_starts_with($r, 'min:')) {
                    $min = (int) substr($r, 4);
                    if (strlen((string)$value) < $min) $errors[$field][] = "{$field} يحتاج {$min} أحرف على الأقل.";
                } elseif (str_starts_with($r, 'max:')) {
                    $max = (int) substr($r, 4);
                    if (strlen((string)$value) > $max) $errors[$field][] = "{$field} لا يجب أن يتجاوز {$max} حرف.";
                } elseif ($r === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون رقماً.";
                } elseif ($r === 'integer' && !ctype_digit(ltrim((string)$value, '-'))) {
                    $errors[$field][] = "{$field} يجب أن يكون عدداً صحيحاً.";
                } elseif ($r === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} يجب أن يكون بريداً إلكترونياً.";
                } elseif ($r === 'string' && !is_string($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون نصاً.";
                } elseif ($r === 'array' && !is_array($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون مصفوفة.";
                } elseif ($r === 'date') {
                    $d = \DateTime::createFromFormat('Y-m-d', (string)$value);
                    if (!$d || $d->format('Y-m-d') !== (string)$value) {
                        $errors[$field][] = "{$field} يجب أن يكون تاريخاً صالحاً Y-m-d.";
                    }
                } elseif (str_starts_with($r, 'in:')) {
                    $allowed = explode(',', substr($r, 3));
                    if (!in_array((string)$value, $allowed, true)) {
                        $errors[$field][] = "{$field} خارج القيم المسموحة.";
                    }
                }
            }
        }
        return $errors;
    }
}

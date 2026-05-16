<?php

namespace App\Core;


class Validator {
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
    public static function validate(array $data, array $rules): array {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $ruleList = explode('|', $rule);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $r) {
                // required
                if ($r === 'required' && (empty($value) && $value !== 0 && $value !== '0' && $value !== 0.0)) {
                    $errors[$field][] = "حقل {$field} مطلوب";
                    break; // لا حاجة لفحص باقي القواعد
                }

                // القيمة فارغة + ليست مطلوبة → تخطي
                if ($value === null || $value === '') {
                    continue;
                }

                // min:N (عدد الأحرف)
                if (str_starts_with($r, 'min:')) {
                    $min = (int) substr($r, 4);
                    if (strlen((string)$value) < $min) {
                        $errors[$field][] = "{$field} يجب أن يكون {$min} أحرف على الأقل";
                    }
                }

                // max:N (عدد الأحرف)
                elseif (str_starts_with($r, 'max:')) {
                    $max = (int) substr($r, 4);
                    if (strlen((string)$value) > $max) {
                        $errors[$field][] = "{$field} يجب ألا يتجاوز {$max} حرف";
                    }
                }

                // numeric
                elseif ($r === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون رقماً";
                }

                // integer
                elseif ($r === 'integer' && !ctype_digit(ltrim((string)$value, '-'))) {
                    $errors[$field][] = "{$field} يجب أن يكون عدداً صحيحاً";
                }

                // email
                elseif ($r === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "{$field} يجب أن يكون بريداً إلكترونياً صالحاً";
                }

                // string
                elseif ($r === 'string' && !is_string($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون نصاً";
                }

                // array
                elseif ($r === 'array' && !is_array($value)) {
                    $errors[$field][] = "{$field} يجب أن يكون مصفوفة";
                }

                // date (Y-m-d أو Y-m-d\TH:i أو Y-m-d H:i:s)
                elseif ($r === 'date') {
                    $val = (string)$value;
                    $valid = false;
                    foreach (['Y-m-d', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
                        $d = \DateTime::createFromFormat($fmt, $val);
                        if ($d && $d->format($fmt) === $val) {
                            $valid = true;
                            break;
                        }
                    }
                    if (!$valid) {
                        $errors[$field][] = "{$field} يجب أن يكون تاريخاً صالحاً";
                    }
                }

                // in:val1,val2,val3
                elseif (str_starts_with($r, 'in:')) {
                    $allowed = explode(',', substr($r, 3));
                    if (!in_array((string)$value, $allowed, true)) {
                        $errors[$field][] = "{$field} يجب أن يكون أحد: " . implode(', ', $allowed);
                    }
                }

                // min_value:N
                elseif (str_starts_with($r, 'min_value:')) {
                    $minVal = (float) substr($r, 10);
                    if (is_numeric($value) && (float)$value < $minVal) {
                        $errors[$field][] = "{$field} يجب أن يكون {$minVal} أو أكثر";
                    }
                }

                // max_value:N
                elseif (str_starts_with($r, 'max_value:')) {
                    $maxVal = (float) substr($r, 10);
                    if (is_numeric($value) && (float)$value > $maxVal) {
                        $errors[$field][] = "{$field} يجب ألا يتجاوز {$maxVal}";
                    }
                }

                // strong_password
                elseif ($r === 'strong_password') {
                    $weak = ['password', '123456', '12345678', 'qwerty', 'abc123', 'password1', 'admin', 'letmein', 'welcome', '111111', '000000'];
                    if (in_array(strtolower((string)$value), $weak, true)) {
                        $errors[$field][] = 'كلمة المرور ضعيفة جداً. اختر كلمة مرور أقوى.';
                    }
                }
            }
        }
        return $errors;
    }
}

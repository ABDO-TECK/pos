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
                if ($r === 'required' && ($value === null || $value === '' || (is_array($value) && count($value) === 0))) {
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
                    if (mb_strlen((string)$value, 'UTF-8') < $min) {
                        $errors[$field][] = "{$field} يجب أن يكون {$min} أحرف على الأقل";
                    }
                }

                // max:N (عدد الأحرف)
                elseif (str_starts_with($r, 'max:')) {
                    $max = (int) substr($r, 4);
                    if (mb_strlen((string)$value, 'UTF-8') > $max) {
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
                    $val = (string)$value;
                    // 1. Minimum length
                    if (mb_strlen($val, 'UTF-8') < 8) {
                        $errors[$field][] = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
                    }
                    // 2. Must contain at least one digit
                    if (!preg_match('/\d/', $val)) {
                        $errors[$field][] = 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.';
                    }
                    // 3. Must contain at least one letter (Latin or Arabic)
                    if (!preg_match('/[a-zA-Z\p{Arabic}]/u', $val)) {
                        $errors[$field][] = 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.';
                    }
                    // 4. Common password blocklist
                    $weak = [
                        'password', '123456', '12345678', 'qwerty', 'abc123',
                        'password1', 'admin', 'letmein', 'welcome', '111111',
                        '000000', 'password123', 'admin123', '1234567890',
                        'iloveyou', 'monkey', 'dragon', 'master', 'login'
                    ];
                    if (in_array(strtolower($val), $weak, true)) {
                        $errors[$field][] = 'كلمة المرور ضعيفة جداً. اختر كلمة مرور أقوى.';
                    }
                }
            }
        }
        return $errors;
    }
}

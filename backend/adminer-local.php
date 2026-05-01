<?php
/**
 * Adminer wrapper — يسمح بالدخول بدون كلمة مرور لـ MySQL المحلي.
 * هذا آمن لأن MySQL يعمل على 127.0.0.1 فقط ولا يمكن الوصول إليه من الخارج.
 */
function adminer_object() {
    class AdminerNoPassword extends Adminer {
        function login($login, $password) {
            return true; // السماح بالدخول بدون كلمة مرور
        }
    }
    return new AdminerNoPassword;
}

require __DIR__ . '/adminer.php';

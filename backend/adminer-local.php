<?php
function adminer_object() {
    class AdminerSoftware extends Adminer {
        public function login($login, $password) {
            // Allow logging in without a password
            return true;
        }
    }
    return new AdminerSoftware;
}
include __DIR__ . '/adminer.php';

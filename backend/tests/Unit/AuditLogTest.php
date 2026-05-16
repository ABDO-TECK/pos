<?php
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testLogDoesNotThrowOnFailure()
    {
        // AuditLog::log يجب ألا يُوقف التطبيق حتى لو فشلت قاعدة البيانات
        // هذا الاختبار يتحقق من أن الدالة تعمل بصمت
        $this->expectNotToPerformAssertions();
        // في بيئة الاختبار بدون DB، يجب أن يفشل بصمت
        \App\Helpers\AuditLog::log(null, 'test', 'test', null);
    }
}

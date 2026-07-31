<?php
namespace App\Controllers;

use App\Config\Database;
use App\Models\InventoryEvent;

class SseController
{
    /**
     * GET /api/sse/inventory?last_id=0
     *
     * نمط Short-Poll بدلاً من SSE المستمر:
     * يُرسل الأحداث الجديدة مرة واحدة ثم يُغلق الاتصال فوراً.
     * المتصفح يعيد الاتصال تلقائياً عبر EventSource retry.
     *
     * هذا يمنع تجمّد خادم PHP المدمج (php -S) الذي يعمل بـ thread واحد.
     */
    public function inventory(): void
    {
        header('Content-Type: text/event-stream', true);
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (ob_get_level()) ob_end_clean();

        $db = Database::getInstance();
        $model = new InventoryEvent($db);
        $lastId = (int)($_GET['last_id'] ?? 0);

        // إذا لم يُرسل last_id، ابدأ من آخر ID موجود
        if ($lastId === 0) {
            $stmt = $db->query('SELECT COALESCE(MAX(id), 0) FROM inventory_events');
            $lastId = (int)$stmt->fetchColumn();
        }

        $events = $model->getAfter($lastId);

        if (!empty($events)) {
            foreach ($events as $event) {
                echo "id: {$event['id']}\n";
                echo "event: inventory_update\n";
                echo "data: " . json_encode([
                    'product_id' => (int)$event['product_id'],
                    'action'     => $event['action'],
                    'quantity'   => (int)$event['quantity'],
                    'delta'      => (int)$event['delta'],
                    'timestamp'  => $event['created_at'],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        } else {
            // heartbeat فقط
            echo ": heartbeat\n\n";
        }

        // تنظيف دوري (مرة كل 20 طلب تقريباً)
        if (rand(1, 20) === 1) {
            $model->cleanup();
        }

        // إخبار المتصفح بإعادة الاتصال بعد 3 ثوانٍ
        echo "retry: 3000\n\n";
        flush();
    }
}


<?php
/**
 * صفحة طباعة الفاتورة
 * لا نضيف رأس وتذييل الصفحة هنا حتى تكون صفحة مخصصة للطباعة فقط
 * تم تحسين الصفحة بفصل CSS و JavaScript إلى ملفات خارجية
 */
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// التحقق من تفعيل النظام أولاً
if (!isSystemActivated()) {
    header("Location: ../../login.php");
    exit();
}

// التحقق من صلاحية الوصول
if (!isLoggedIn()) {
    header("Location: ../../login.php");
    exit();
}

// التحقق من أن المستخدم كاشير
if (!hasRole('cashier')) {
    header("Location: ../../unauthorized.php");
    exit();
}

// تحديث نشاط الجلسة
updateSessionActivity($_SESSION['user_id']);

// الحصول على معرف الفرع والكاشير
$branch_id = $_SESSION['branch_id'];
$cashier_id = $_SESSION['user_id'];

// الحصول على معرف الفاتورة أو معرف الطلب
$invoice_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order_id_param = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// التحقق من وجود معامل الطباعة التلقائية
$auto_print = isset($_GET['auto_print']) && $_GET['auto_print'] == 'true';

// إذا تم تمرير order_id، نحتاج للبحث عن الفاتورة المرتبطة بهذا الطلب
if ($invoice_id <= 0 && $order_id_param > 0) {
    $find_invoice_sql = "SELECT id FROM invoices WHERE order_id = $order_id_param AND cashier_id = $cashier_id";
    $find_invoice_result = mysqli_query($conn, $find_invoice_sql);
    
    if (mysqli_num_rows($find_invoice_result) > 0) {
        $found_invoice = mysqli_fetch_assoc($find_invoice_result);
        $invoice_id = $found_invoice['id'];
    }
}

if ($invoice_id <= 0) {
    // تحسين عرض رسالة الخطأ بأسلوب أفضل
    header("Content-Type: text/html; charset=UTF-8");
    echo '<!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>خطأ في الفاتورة</title>       
        <?php echo linkTag("../../assets/css/bootstrap/bootstrap.min.css"); ?>
        <?php echo linkTag("../../assets/css/fontawesome/css/all.min.css"); ?>
        <style>
            @font-face {
                font-family: "Tajawal";
                src: url("../../../../assets/fonts/Tajawal/Tajawal-Regular.ttf") format("truetype");
                font-weight: 400;
                font-style: normal;
            }
            body { font-family: "Tajawal", sans-serif; background-color: #f8f9fa; }
            .error-container { max-width: 500px; margin: 100px auto; text-align: center; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                    <h2 class="mt-3 mb-3">خطأ في الفاتورة</h2>
                    <p class="lead">معرف الفاتورة غير صحيح أو لا توجد فاتورة مرتبطة بهذا الطلب.</p>
                    <a href="dashboard.php" class="btn btn-primary mt-3">
                        <i class="fas fa-home me-1"></i> العودة للوحة التحكم
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// استعلام للحصول على بيانات الفاتورة
$invoice_sql = "SELECT i.*, c.name as cashier_name
                FROM invoices i
                JOIN users c ON i.cashier_id = c.id
                WHERE i.id = $invoice_id AND i.cashier_id = $cashier_id";

$invoice_result = mysqli_query($conn, $invoice_sql);

if (mysqli_num_rows($invoice_result) == 0) {
    // تحسين عرض رسالة الخطأ بأسلوب أفضل
    header("Content-Type: text/html; charset=UTF-8");
    echo '<!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>خطأ في الفاتورة</title>       
        <?php echo linkTag("../../assets/css/bootstrap/bootstrap.min.css"); ?>
        <?php echo linkTag("../../assets/css/fontawesome/css/all.min.css"); ?>
        <style>
            @font-face {
                font-family: "Tajawal";
                src: url("../../../../assets/fonts/Tajawal/Tajawal-Regular.ttf") format("truetype");
                font-weight: 400;
                font-style: normal;
            }
            body { font-family: "Tajawal", sans-serif; background-color: #f8f9fa; }
            .error-container { max-width: 500px; margin: 100px auto; text-align: center; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="card shadow-sm">
                <div class="card-body p-5">
                    <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                    <h2 class="mt-3 mb-3">خطأ في الفاتورة</h2>
                    <p class="lead">الفاتورة غير موجودة أو ليس لديك صلاحية الوصول إليها.</p>
                    <a href="dashboard.php" class="btn btn-primary mt-3">
                        <i class="fas fa-home me-1"></i> العودة للوحة التحكم
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

$invoice = mysqli_fetch_assoc($invoice_result);
$order_id = $invoice['order_id'];
$is_manual_invoice = ($order_id === null || $order_id == 0);

// إذا كانت فاتورة يدوية، نحتاج لمعلومات مختلفة
if ($is_manual_invoice) {
    // للفواتير اليدوية، نحصل على معلومات الفرع من المستخدم
    $branch_sql = "SELECT b.name as branch_name, b.address as branch_address, b.phone as branch_phone FROM users u JOIN branches b ON u.branch_id = b.id WHERE u.id = $cashier_id";
    $branch_result = mysqli_query($conn, $branch_sql);
    $branch_data = mysqli_fetch_assoc($branch_result);
    
    $invoice['branch_name'] = $branch_data['branch_name'];
    $invoice['branch_address'] = $branch_data['branch_address'];
    $invoice['branch_phone'] = $branch_data['branch_phone'];
    $invoice['table_number'] = 'فاتورة يدوية';
    $invoice['waiter_name'] = 'غير محدد';
    $invoice['daily_order_number'] = null;
    $invoice['order_status'] = null;
    $invoice['order_amount'] = null;
    $invoice['order_notes'] = null;
} else {
    // للفواتير العادية، نحصل على بيانات الطلب
    $order_sql = "SELECT o.table_id, o.waiter_id, o.status as order_status, o.amount as order_amount, o.notes as order_notes,
                  o.daily_order_number, o.order_type, o.customer_name, o.customer_phone, o.customer_address,
                  t.number as table_number,
                  w.name as waiter_name,
                  b.name as branch_name, b.address as branch_address, b.phone as branch_phone
                  FROM orders o
                  LEFT JOIN tables t ON o.table_id = t.id
                  LEFT JOIN users w ON o.waiter_id = w.id
                  JOIN branches b ON o.branch_id = b.id
                  WHERE o.id = $order_id AND o.branch_id = $branch_id";
    
    $order_result = mysqli_query($conn, $order_sql);
    
    if (mysqli_num_rows($order_result) == 0) {
        // تحسين عرض رسالة الخطأ بأسلوب أفضل
        header("Content-Type: text/html; charset=UTF-8");
        echo '<!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>خطأ في الفاتورة</title>       
            <?php echo linkTag("../../assets/css/bootstrap/bootstrap.min.css"); ?>
            <?php echo linkTag("../../assets/css/fontawesome/css/all.min.css"); ?>
            <style>
                @font-face {
                    font-family: "Tajawal";
                    src: url("../../../../assets/fonts/Tajawal/Tajawal-Regular.ttf") format("truetype");
                    font-weight: 400;
                    font-style: normal;
                }
                body { font-family: "Tajawal", sans-serif; background-color: #f8f9fa; }
                .error-container { max-width: 500px; margin: 100px auto; text-align: center; }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="card shadow-sm">
                    <div class="card-body p-5">
                        <i class="fas fa-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
                        <h2 class="mt-3 mb-3">خطأ في الفاتورة</h2>
                        <p class="lead">الطلب غير موجود أو ليس لديك صلاحية الوصول إليه.</p>
                        <a href="dashboard.php" class="btn btn-primary mt-3">
                            <i class="fas fa-home me-1"></i> العودة للوحة التحكم
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        exit();
    }
    
    $order_data = mysqli_fetch_assoc($order_result);
    $invoice = array_merge($invoice, $order_data);
}

// الحصول على عناصر الطلب
if (!$is_manual_invoice) {
    $items_sql = "SELECT oi.*, 
                  COALESCE(p.name, 'منتج محذوف') as product_name, 
                  p.category,
                  pv.size as variant_size
                 FROM order_items oi
                 LEFT JOIN products p ON oi.product_id = p.id
                 LEFT JOIN product_variants pv ON oi.product_variant_id = pv.id
                 WHERE oi.order_id = $order_id
                 ORDER BY oi.id ASC";
    $items_result = mysqli_query($conn, $items_sql);
} else {
    // للفواتير اليدوية، نحصل على العناصر من جدول invoice_items
    $manual_items_sql = "SELECT product_name as name, quantity, price, total FROM invoice_items WHERE invoice_id = $invoice_id ORDER BY id ASC";
    $manual_items_result = mysqli_query($conn, $manual_items_sql);
    $manual_items = [];
    if ($manual_items_result && mysqli_num_rows($manual_items_result) > 0) {
        while ($item = mysqli_fetch_assoc($manual_items_result)) {
            $manual_items[] = $item;
        }
    }
    $items_result = null; // لن نحتاج لاستعلام قاعدة البيانات
}

// الحصول على إعدادات النظام
$settings = getAllSettings();
$time_format = $settings['time_format'] ?? '12';

// استخدام قيمة الضريبة المحفوظة في الفاتورة أو القيمة الحالية للفواتير القديمة
$invoice_tax_rate = isset($invoice['tax_rate']) ? $invoice['tax_rate'] : ($settings['tax_rate'] ?? 14);
$invoice_tax_amount = isset($invoice['tax_amount']) ? $invoice['tax_amount'] : null;

// تسجيل نشاط الطباعة
if ($is_manual_invoice) {
    logActivity($cashier_id, "طباعة فاتورة", "تمت طباعة الفاتورة اليدوية رقم #$invoice_id");
} else {
    logActivity($cashier_id, "طباعة فاتورة", "تمت طباعة الفاتورة رقم #$invoice_id للطلب #$order_id");
}
?>
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>طباعة الفاتورة #<?php echo $invoice_id; ?></title>
    <!-- تم نقل جميع أنماط الطباعة إلى ملف CSS خارجي محسن -->
    <?php echo linkTag('../../assets/css/bootstrap/bootstrap.min.css'); ?>
    <?php echo linkTag('../../assets/css/fontawesome/css/all.min.css'); ?>
    <style>
        @font-face {
            font-family: "Tajawal";
            src: url("../../../../assets/fonts/Tajawal/Tajawal-Regular.ttf") format("truetype");
            font-weight: 400;
            font-style: normal;
        }
        body { font-family: "Tajawal", sans-serif; background-color: #f8f9fa; }
    </style>
    <!-- CSS خارجي لمعالجة المظهر -->
    <?php echo linkTag('../../assets/css/print-invoice.css'); ?>
    <!-- JavaScript خارجي لمعالجة الطباعة -->
    <?php echo scriptTag('../../assets/js/print-invoice.js', ['defer' => true]); ?>
    <script>
    // معالجة تحميل اللوجو للطباعة عبر QZ Tray في PHPDesktop
    document.addEventListener('DOMContentLoaded', function() {
        const logoImage = document.querySelector('.logo-image');
        if (logoImage) {
            // استخدام الإعدادات الديناميكية من الخادم
            const serverIP = '<?php echo $server_ip; ?>';
            const currentHostname = window.location.hostname;
            
            // في حالة PHPDesktop، نحاول استخدام base64 مباشرة للطباعة
            if (currentHostname === serverIP || currentHostname === '127.0.0.1' || currentHostname === 'localhost') {
                const base64Logo = '<?php echo $logo_base64; ?>';
                if (base64Logo && base64Logo !== '') {
                    // تحديث src للصورة لاستخدام base64 في بيئة PHPDesktop
                    logoImage.addEventListener('error', function() {
                        if (this.src !== base64Logo) {
                            this.src = base64Logo;
                        }
                    });
                }
            }
        }
    });
    </script>
</head>
<body>
    <div class="invoice-container">
        <!-- أزرار التحكم - تظهر فقط على الشاشة وليس عند الطباعة -->
        <div class="print-buttons no-print">
            <a id="back-to-invoice-btn" href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary ms-2">
                <i class="fas fa-arrow-left me-1"></i> العودة
            </a>
        </div>
        
        <!-- رأس الفاتورة -->
        <div class="invoice-header">
            <?php if (!empty($settings['cafe_logo'])): ?>
                <?php 
                // قراءة إعدادات الخادم من ملف settings.json ديناميكياً
                function getServerSettings() {
                    $settings_file = '../../../settings.json';
                    if (file_exists($settings_file)) {
                        $settings_content = file_get_contents($settings_file);
                        $settings_data = json_decode($settings_content, true);
                        if ($settings_data && isset($settings_data['web_server']['listen_on'])) {
                            return $settings_data['web_server']['listen_on'];
                        }
                    }
                    // القيم الافتراضية في حالة عدم وجود الملف
                    return ['127.0.0.1', 0];
                }
                
                // الحصول على إعدادات الخادم الديناميكية
                $server_settings = getServerSettings();
                $server_ip = $server_settings[0];
                $server_port = $server_settings[1];
                
                // إذا كان المنفذ 0، استخدم المنفذ الحالي من $_SERVER
                if ($server_port == 0) {
                    $server_port = $_SERVER['SERVER_PORT'];
                }
                
                // تحديد مسار اللوجو - استخدام مسار مطلق لحل مشكلة QZ Tray في PHPDesktop
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $port_suffix = ($server_port != '80' && $server_port != '443') ? ':' . $server_port : '';
                $logo_path = $protocol . '://' . $server_ip . $port_suffix . '/' . $settings['cafe_logo'];
                
                // إضافة fallback للمسار المحلي في حالة فشل المسار المطلق
                $logo_fallback_path = '../../' . $settings['cafe_logo'];
                
                // إنشاء مسار base64 كحل أخير للطباعة عبر QZ Tray
                $logo_base64 = '';
                $logo_file_path = '../../' . $settings['cafe_logo'];
                if (file_exists($logo_file_path)) {
                    $image_data = file_get_contents($logo_file_path);
                    if ($image_data !== false) {
                        $image_info = getimagesize($logo_file_path);
                        if ($image_info !== false) {
                            $logo_base64 = 'data:' . $image_info['mime'] . ';base64,' . base64_encode($image_data);
                        }
                    }
                }
                ?>
                <?php 
                // تحديد حجم اللوجو بناءً على الإعدادات
                $logo_size = $settings['logo_size'] ?? 'small';
                $logo_height_screen = '70px'; // الحجم الافتراضي للشاشة (صغير)
                $logo_height_print = '15mm'; // الحجم الافتراضي للطباعة (صغير)
                
                if ($logo_size == 'medium') {
                    $logo_height_screen = '90px';
                    $logo_height_print = '19mm';
                } elseif ($logo_size == 'large') {
                    $logo_height_screen = '120px';
                    $logo_height_print = '25mm';
                }
                ?>
                <img src="<?php echo $logo_path; ?>" alt="شعار المقهى" class="img-fluid logo-image" 
                     style="max-height: <?php echo $logo_height_screen; ?>; margin-bottom: 10px;"
                     onerror="this.src='<?php echo $logo_fallback_path; ?>'; if(this.src === '<?php echo $logo_fallback_path; ?>' && '<?php echo $logo_base64; ?>' !== '') { this.src='<?php echo $logo_base64; ?>'; } this.onerror=null;">
                <style>
                @media print {
                    .logo-image {
                        max-height: <?php echo $logo_height_print; ?> !important;
                        width: auto !important;
                        display: block !important;
                        margin: 0 auto 2mm auto !important;
                        -webkit-print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                    .branch-info {
                        margin: 2mm 0 !important;
                        padding: 1mm !important;
                        font-size: 2.5mm !important;
                        line-height: 1.2 !important;
                    }
                    .branch-info p {
                        margin: 0.5mm 0 !important;
                        padding: 0 !important;
                    }
                }
                /* تحسينات إضافية للوجو */
                .logo-image {
                    max-width: 100% !important;
                    height: auto !important;
                    object-fit: contain !important;
                }
                </style>
            <?php endif; ?>
            <h2><?php echo $settings['cafe_name'] ?? 'نظام إدارة المقهى'; ?></h2>
            <?php if (!empty($settings['address'])): ?>
                <p><?php echo $settings['address']; ?></p>
            <?php endif; ?>
            <?php if (!empty($settings['phone'])): ?>
                <p>هاتف: <?php echo $settings['phone']; ?></p>
            <?php endif; ?>
            <?php if (!empty($settings['tax_number'])): ?>
                <p>الرقم الضريبي: <?php echo $settings['tax_number']; ?></p>
            <?php endif; ?>
            
            <div class="invoice-title">
                <?php if ($is_manual_invoice): ?>
                    رقم الفاتورة: <?php echo $invoice_id; ?>
                <?php elseif (!empty($invoice['daily_order_number'])): ?>
                    رقم الطلب: #<?php echo $invoice['daily_order_number']; ?>
                <?php else: ?>
                    رقم الطلب: #<?php echo $order_id; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- تفاصيل الفاتورة -->
        <div class="invoice-details">
            <div class="row">
                <div class="col-6">
                    <div class="info-row">
                        <span>التاريخ :</span>
                        <span><?php echo date('Y-m-d', strtotime($invoice['created_at'])); ?></span>
                    </div>
                    <div class="info-row">
                        <span>الوقت :</span>
                        <span style="direction: ltr; text-align: right;"><?php 
                            $time = strtotime($invoice['created_at']);
                            echo date('h:i', $time) . ' ' . strtoupper(date('A', $time));
                        ?></span>
                    </div>
                    <div class="info-row">
                        <span>الكاشير:</span>
                        <span><?php echo $invoice['cashier_name']; ?></span>
                    </div>
                </div>
                <div class="col-6">
                    <div class="info-row">
                        <span>الفرع:</span>
                        <span><?php echo $invoice['branch_name']; ?></span>
                    </div>
                    <?php if (!empty($invoice['order_type']) && $invoice['order_type'] == 'delivery'): ?>
                        <div class="info-row">
                            <span>نوع الطلب:</span>
                            <span>دليفري</span>
                        </div>
                        <?php if (!empty($invoice['customer_name'])): ?>
                        <div class="info-row">
                            <span>اسم العميل:</span>
                            <span><?php echo $invoice['customer_name']; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_phone'])): ?>
                        <div class="info-row">
                            <span>رقم الهاتف:</span>
                            <span><?php echo $invoice['customer_phone']; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_address'])): ?>
                        <div class="info-row">
                            <span>العنوان:</span>
                            <span><?php echo $invoice['customer_address']; ?></span>
                        </div>
                        <?php endif; ?>
                    <?php elseif (!empty($invoice['order_type']) && $invoice['order_type'] == 'takeaway'): ?>
                        <div class="info-row">
                            <span>نوع الطلب:</span>
                            <span>تيكاواي</span>
                        </div>
                        <?php if (!empty($invoice['customer_name'])): ?>
                        <div class="info-row">
                            <span>اسم العميل:</span>
                            <span><?php echo $invoice['customer_name']; ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['customer_phone'])): ?>
                        <div class="info-row">
                            <span>رقم الهاتف:</span>
                            <span><?php echo $invoice['customer_phone']; ?></span>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="info-row">
                            <span>رقم الطاولة:</span>
                            <span><?php echo $invoice['table_number']; ?></span>
                        </div>
                        <?php if (!empty($invoice['waiter_name'])): ?>
                        <div class="info-row">
                            <span>النادل:</span>
                            <span><?php echo $invoice['waiter_name']; ?></span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- جدول المنتجات -->
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المنتج</th>
                        <th class="text-center">الكمية</th>
                        <th class="text-start">السعر</th>
                        <th class="text-start">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    if ($is_manual_invoice) {
                        // للفواتير اليدوية
                        if (is_array($manual_items) && !empty($manual_items)) {
                            foreach ($manual_items as $item):
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo htmlspecialchars($item['name'] ?? 'عنصر'); ?></td>
                            <td class="text-center"><?php echo $item['quantity'] ?? 1; ?></td>
                            <td class="text-start"><?php echo number_format($item['price'] ?? 0, 2); ?></td>
                            <td class="text-start"><?php echo number_format(($item['price'] ?? 0) * ($item['quantity'] ?? 1), 2); ?></td>
                        </tr>
                    <?php 
                            endforeach;
                        } else {
                    ?>
                        <tr>
                            <td>1</td>
                            <td>عناصر متنوعة</td>
                            <td class="text-center">1</td>
                            <td class="text-start"><?php echo number_format($invoice['amount'], 2); ?></td>
                            <td class="text-start"><?php echo number_format($invoice['amount'], 2); ?></td>
                        </tr>
                    <?php 
                        }
                    } else {
                        // للطلبات العادية
                        if ($items_result && mysqli_num_rows($items_result) > 0) {
                            mysqli_data_seek($items_result, 0);
                            while ($item = mysqli_fetch_assoc($items_result)): 
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td>
                                <?php 
                                echo $item['product_name'];
                                if (!empty($item['variant_size'])) {
                                    echo ' - ' . $item['variant_size'];
                                }
                                ?>
                            </td>
                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                            <td class="text-start"><?php echo number_format($item['price'], 2); ?></td>
                            <td class="text-start"><?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                        </tr>
                    <?php 
                            endwhile;
                        } else {
                    ?>
                        <tr>
                            <td colspan="5" class="text-center">لا توجد منتجات في هذه الفاتورة</td>
                        </tr>
                    <?php 
                        }
                    } ?>
                </tbody>
                <tfoot>
                    <?php 
                    // حساب المجموع الفرعي الصحيح من عناصر الطلب
                    $calculated_subtotal = 0;
                    if ($is_manual_invoice) {
                        if (is_array($manual_items) && !empty($manual_items)) {
                            foreach ($manual_items as $item) {
                                $calculated_subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                            }
                        } else {
                            $calculated_subtotal = $invoice['amount'];
                        }
                    } else {
                        if ($items_result && mysqli_num_rows($items_result) > 0) {
                            mysqli_data_seek($items_result, 0);
                            while ($item = mysqli_fetch_assoc($items_result)) {
                                $calculated_subtotal += $item['price'] * $item['quantity'];
                            }
                        } else {
                            $calculated_subtotal = $invoice['amount'];
                        }
                    }
                    
                    // التحقق من وجود خصومات أو رسوم خدمة أو ضرائب
                    $has_discount = $invoice['discount'] > 0;
                    $has_service_charge = isset($invoice['service_charge']) && $invoice['service_charge'] > 0;
                    $has_tax = $invoice_tax_rate > 0;
                    $show_subtotal = $has_discount || $has_service_charge || $has_tax;
                    ?>
                    
                    <?php if ($show_subtotal): ?>
                    <tr>
                        <th colspan="4" class="text-start">المجموع الفرعي</th>
                        <th class="text-start"><?php echo number_format($calculated_subtotal, 2); ?></th>
                    </tr>
                    <?php endif; ?>
                    <?php if ($invoice_tax_rate > 0): ?>
                        <?php 
                        // استخدام مبلغ الضريبة المحفوظ أو حسابه للفواتير القديمة على المبلغ الأصلي
                        if ($invoice_tax_amount !== null) {
                            $tax_amount = $invoice_tax_amount;
                        } else {
                            $tax_amount = $calculated_subtotal * ($invoice_tax_rate / 100);
                        }
                        ?>
                        <tr>
                            <th colspan="4" class="text-start">ضريبة القيمة المضافة (<?php echo $invoice_tax_rate; ?>%)</th>
                            <th class="text-start"><?php echo number_format($tax_amount, 2); ?></th>
                        </tr>
                    <?php endif; ?>
                    <?php if (isset($invoice['service_charge']) && $invoice['service_charge'] > 0): ?>
                        <tr>
                            <th colspan="4" class="text-start">رسوم الخدمة<?php 
                                // عرض نسبة رسوم الخدمة إذا كانت متوفرة
                                if (isset($invoice['service_charge_rate']) && $invoice['service_charge_rate'] > 0) {
                                    echo ' (' . $invoice['service_charge_rate'] . '%)';
                                }
                            ?></th>
                            <th class="text-start"><?php echo number_format($invoice['service_charge'], 2); ?></th>
                        </tr>
                    <?php endif; ?>
                    <?php if ($invoice['discount'] > 0): ?>
                        <tr>
                            <th colspan="4" class="text-start">الخصم<?php 
                                // حساب نسبة الخصم من المبلغ قبل الخصم
                                $discount_percentage = 0;
                                $amount_before_discount = $invoice['amount'];
                                if ($amount_before_discount > 0) {
                                    $discount_percentage = ($invoice['discount'] / $amount_before_discount) * 100;
                                }
                                echo ' (' . number_format($discount_percentage, 2) . '%)';
                            ?></th>
                            <th class="text-start"><?php echo number_format($invoice['discount'], 2); ?></th>
                        </tr>
                    <?php endif; ?>
                    <?php if ($invoice_tax_rate > 0): ?>
                        <tr>
                            <th colspan="4" class="text-start fw-bold">المجموع مع الضريبة</th>
                            <th class="text-start fw-bold"><?php echo number_format($calculated_subtotal - $invoice['discount'] + (isset($invoice['service_charge']) ? $invoice['service_charge'] : 0) + $tax_amount, 2); ?></th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th colspan="4" class="text-start fw-bold">المجموع النهائي</th>
                            <th class="text-start fw-bold"><?php 
                                $final_amount = $calculated_subtotal - $invoice['discount'];
                                // إضافة رسوم الخدمة إذا كانت موجودة
                                if (isset($invoice['service_charge']) && $invoice['service_charge'] > 0) {
                                    $final_amount += $invoice['service_charge'];
                                }
                                echo number_format($final_amount, 2);
                            ?></th>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th colspan="4" class="text-start">طريقة الدفع</th>
                        <th class="text-start">
                            <?php
                            switch ($invoice['payment_method']) {
                                case 'cash':
                                    echo 'نقد';
                                    break;
                                case 'credit_card':
                                    echo 'بطاقة ائتمان';
                                    break;
                                case 'bank_transfer':
                                    echo 'تحويل بنكي';
                                    break;
                                default:
                                    echo $invoice['payment_method'];
                            }
                            ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- تذييل الفاتورة -->
        <div class="invoice-footer">
            <!-- QR Code -->
            <div class="qr-code">
                <?php
                // استخدام رابط QR Code من الإعدادات أو البيانات الافتراضية
                $qr_code_url = $settings['qr_code_url'] ?? '';
                
                if (!empty($qr_code_url)) {
                    // استخدام الرابط المخصص من الإعدادات
                    $qr_data = $qr_code_url;
                } else {
                    // استخدام البيانات الافتراضية للفاتورة
                    if ($invoice_tax_rate > 0) {
                        if ($invoice_tax_amount !== null) {
                            $tax_amount_qr = $invoice_tax_amount;
                        } else {
                            $tax_amount_qr = $calculated_subtotal * ($invoice_tax_rate / 100);
                        }
                        $final_amount_qr = $calculated_subtotal - $invoice['discount'] + (isset($invoice['service_amount']) ? $invoice['service_amount'] : 0) + $tax_amount_qr;
                    } else {
                        $final_amount_qr = $calculated_subtotal - $invoice['discount'];
                        // إضافة رسوم الخدمة إذا كانت موجودة
                        if (isset($invoice['service_amount']) && $invoice['service_amount'] > 0) {
                            $final_amount_qr += $invoice['service_amount'];
                        }
                    }
                    $qr_data = "Invoice:$invoice_id|Date:" . date('Y-m-d', strtotime($invoice['created_at'])) . "|Amount:" . number_format($final_amount_qr, 2, '.', '');
                }
                ?>
                <?php
                // تضمين مولد QR Code المحلي
                require_once '../../includes/qr_generator.php';
                
                // إنشاء QR Code محليا
                $qr_image_data = generateLocalQRCode($qr_data, 300);
                
                if ($qr_image_data) {
                    echo '<img src="' . $qr_image_data . '" alt="QR Code" class="img-fluid">';
                } else {
                    echo '<div class="alert alert-warning">تعذر إنشاء QR Code</div>';
                }
                ?>
                <p class="small text-dark mt-1">امسح الـ QR Code </p>
            </div>
            
            <!-- معلومات الفرع -->
            <?php if (!empty($invoice['branch_address']) || !empty($invoice['branch_phone'])): ?>
                <div class="branch-info">
                    <?php if (!empty($invoice['branch_address'])): ?>
                        <p><strong>العنوان:</strong> <?php echo htmlspecialchars($invoice['branch_address']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['branch_phone'])): ?>
                        <p><strong>الهاتف:</strong> <?php echo htmlspecialchars($invoice['branch_phone']); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($settings['receipt_footer'])): ?>
                <p><?php echo nl2br(htmlspecialchars($settings['receipt_footer'])); ?></p>
            <?php else: ?>
                <p>شكراً لزيارتكم. نتمنى لكم تجربة ممتعة ونرحب بزيارتكم مرة أخرى.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="footer-text">
        powered by injezly
        phone: 01096352154
    </div>
    
    <!-- نظام الإشعارات الحديث -->
    <?php echo scriptTag('../../assets/js/notifications.js'); ?>
    
    <!-- QZ Tray Network Config (for mobile access) -->
    <?php echo scriptTag('../../assets/js/qz-config.js'); ?>
    
    <!-- QZ Tray Library -->
    <?php echo scriptTag('../../assets/js/qz_tray/qz-tray.js'); ?>
    
    <!-- QZ Tray Print Handler -->
    <?php echo scriptTag('../../assets/js/qz-print-invoice.js'); ?>
    
    <?php
    // إضافة CSS لقص الهوامش في الطباعة باستخدام مفاهيم escpos-php
     // تم تبسيط الكود لتجنب مشاكل إدارة الاتصال
     echo '<style type="text/css" media="print">';
     echo '@page { margin: 0; size: auto; }';
     echo 'body { margin: 0; padding: 0; }';
     echo '.invoice-container { margin: 0; padding: 0; }';
     echo '</style>';
     

    ?>

    <!-- متغيرات عامة لـ QZ Tray -->
    <script>
        // تمرير بيانات الفاتورة إلى JavaScript
        <?php 
        // تحديد حجم اللوجو للطباعة بناءً على الإعدادات (نفس منطق السطور 297-309)
        $logo_size = $settings['logo_size'] ?? 'small';
        $js_logo_height_print = '15mm'; // الحجم الافتراضي للطباعة (صغير)
        
        if ($logo_size == 'medium') {
            $js_logo_height_print = '19mm';
        } elseif ($logo_size == 'large') {
            $js_logo_height_print = '25mm';
        }
        ?>
        window.invoiceData = {
            id: <?php echo $invoice_id; ?>,
            auto_print: <?php echo $auto_print ? 'true' : 'false'; ?>,
            logo_height: '<?php echo $js_logo_height_print; ?>'
        };
    </script>



</body>
</html>
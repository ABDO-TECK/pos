<?php
/**
 * fix-admin-password.php
 * =====================
 * يصلح كلمة مرور الأدمن ويعرض تشخيصاً كاملاً.
 * افتح في المتصفح: http://localhost/pos/backend/fix-admin-password.php
 * احذف هذا الملف فور الانتهاء!
 */
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>إصلاح تسجيل الدخول</title>
<style>
  body { font-family: Arial; max-width: 700px; margin: 40px auto; padding: 20px; background: #f5f5f5; }
  .card { background: #fff; padding: 24px; border-radius: 8px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
  .ok { color: #16a34a; font-weight: bold; }
  .err { color: #dc2626; font-weight: bold; }
  .warn { color: #d97706; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin-top: 12px; }
  td, th { padding: 8px 12px; border: 1px solid #ddd; text-align: right; }
  th { background: #f0f0f0; }
  button { background: #2563eb; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; cursor: pointer; margin-top: 12px; }
  button:hover { background: #1d4ed8; }
  .delete-btn { background: #dc2626; }
  pre { background: #1e1e1e; color: #d4d4d4; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
</style>
</head>
<body>
<h1>🔧 أداة إصلاح تسجيل الدخول</h1>

<?php
$action = $_POST['action'] ?? '';

try {
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/helpers/EnvLoader.php';

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ── Perform fix ────────────────────────────────────────────
    if ($action === 'fix') {
        $newHash = password_hash('password', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE email = 'admin@pos.com'");
        $stmt->execute([$newHash]);
        if ($stmt->rowCount() === 0) {
            // User doesn't exist — create it
            $stmt2 = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES ('Admin', 'admin@pos.com', ?, 'admin', 1)");
            $stmt2->execute([$newHash]);
        }
        echo '<div class="card"><p class="ok">✅ تم إعادة ضبط كلمة المرور بنجاح!</p><p>يمكنك الآن تسجيل الدخول بـ:<br><strong>البريد:</strong> admin@pos.com<br><strong>الكلمة:</strong> password</p></div>';
    }

    // ── Delete self ────────────────────────────────────────────
    if ($action === 'delete') {
        unlink(__FILE__);
        echo '<div class="card"><p class="ok">✅ تم حذف الملف بنجاح. الموقع آمن الآن.</p></div>';
        exit;
    }

    // ── Diagnosis ──────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id, email, password, role, is_active FROM users WHERE email = 'admin@pos.com' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch();

    $allUsers = $pdo->query("SELECT id, email, role, is_active FROM users ORDER BY id LIMIT 10")->fetchAll();

    echo '<div class="card">';
    echo '<h2>🗄️ حالة قاعدة البيانات</h2>';
    echo '<p class="ok">✅ الاتصال بقاعدة البيانات (' . DB_NAME . ') ناجح</p>';

    echo '<h3>المستخدمون في النظام:</h3><table><tr><th>ID</th><th>البريد</th><th>الدور</th><th>نشط</th></tr>';
    foreach ($allUsers as $u) {
        $activeLabel = $u['is_active'] ? '<span class="ok">✅ نعم</span>' : '<span class="err">❌ لا</span>';
        echo "<tr><td>{$u['id']}</td><td>{$u['email']}</td><td>{$u['role']}</td><td>{$activeLabel}</td></tr>";
    }
    echo '</table></div>';

    echo '<div class="card"><h2>🔑 فحص المستخدم admin@pos.com</h2>';
    if (!$user) {
        echo '<p class="err">❌ المستخدم admin@pos.com غير موجود في قاعدة البيانات!</p>';
    } else {
        $hashInfo   = password_get_info($user['password']);
        $verifyOk   = password_verify('password', $user['password']);
        $isBcrypt   = $hashInfo['algoName'] === 'bcrypt';

        echo '<table>';
        echo '<tr><th>is_active</th><td>' . ($user['is_active'] ? '<span class="ok">1 (نشط)</span>' : '<span class="err">0 (معطّل!)</span>') . '</td></tr>';
        echo '<tr><th>نوع التشفير</th><td>' . ($isBcrypt ? '<span class="ok">bcrypt ✅</span>' : '<span class="err">' . $hashInfo['algoName'] . ' ❌ (خاطئ)</span>') . '</td></tr>';
        echo '<tr><th>password_verify("password")</th><td>' . ($verifyOk ? '<span class="ok">صحيح ✅</span>' : '<span class="err">فاشل ❌</span>') . '</td></tr>';
        echo '</table>';

        if ($verifyOk && $user['is_active']) {
            echo '<p class="ok" style="margin-top:12px">✅ كلمة المرور صحيحة والحساب نشط — المشكلة في طبقة الشبكة/CSRF.</p>';
        } else {
            echo '<p class="err" style="margin-top:12px">❌ يوجد خطأ في البيانات — اضغط "إصلاح" أدناه.</p>';
        }
    }
    echo '</div>';

} catch (Throwable $e) {
    echo '<div class="card"><p class="err">❌ خطأ: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
}
?>

<div class="card">
  <h2>🛠️ الإجراءات</h2>
  <form method="post" style="display:inline">
    <input type="hidden" name="action" value="fix">
    <button type="submit">🔑 إعادة ضبط كلمة مرور الأدمن إلى "password"</button>
  </form>
  &nbsp;&nbsp;
  <form method="post" style="display:inline" onsubmit="return confirm('هل تريد حذف هذا الملف؟')">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="delete-btn">🗑️ احذف هذا الملف (بعد الانتهاء)</button>
  </form>
</div>

</body>
</html>

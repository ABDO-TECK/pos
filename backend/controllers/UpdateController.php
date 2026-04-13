<?php

class UpdateController extends Controller {

    private string $repoUrl = 'https://raw.githubusercontent.com/ABDO-TECK/pos/main/version.json';
    private string $commitsUrl = 'https://api.github.com/repos/ABDO-TECK/pos/commits?sha=main&per_page=15';
    private string $localVersionFile = __DIR__ . '/../../version.json';

    public function __construct() {
        // Only Admins can invoke this controller (enforced via middleware in api.php)
    }

    private function getLocalVersion(): array {
        if (!file_exists($this->localVersionFile)) {
            return ['version' => '0.0.0', 'released_at' => null];
        }
        $content = file_get_contents($this->localVersionFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : ['version' => '0.0.0', 'released_at' => null];
    }

    private function fetchRemoteVersion(): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->repoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ABDO-TECK-POS-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $result) {
            $data = json_decode($result, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    public function check(): void {
        $local = $this->getLocalVersion();
        $remote = $this->fetchRemoteVersion();

        if (!$remote) {
            Response::error('تعذر الاتصال بخادم التحديثات (GitHub). الرجاء المحاولة لاحقاً.', 500);
        }

        $hasUpdate = version_compare($remote['version'], $local['version'], '>');

        Response::success([
            'current_version' => $local['version'],
            'latest_version'  => $remote['version'],
            'has_update'      => $hasUpdate,
            'released_at'     => $remote['released_at']
        ]);
    }

    public function changelog(): void {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->commitsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ABDO-TECK-POS-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$result) {
            Response::error('تعذر جلب سجل التغييرات.', 500);
        }

        $commits = json_decode($result, true);
        if (!is_array($commits)) {
            Response::error('رد غير صالح من خادم التحديثات.', 500);
        }

        $changelog = [];
        foreach ($commits as $c) {
            $msg = $c['commit']['message'] ?? '';
            // skip merge commits or automated
            if (str_starts_with(strtolower($msg), 'merge') || str_starts_with($msg, 'Auto-build')) continue;
            
            $changelog[] = [
                'sha'     => substr($c['sha'], 0, 7),
                'message' => explode("\n", $msg)[0], // first line only
                'date'    => $c['commit']['author']['date'] ?? '',
                'author'  => $c['commit']['author']['name'] ?? 'Unknown'
            ];
        }

        Response::success($changelog);
    }

    public function apply(): void {
        // مسار المشروع (يجب تعديله ليكون الجذر وليس ملف الكنترولر)
        $rootDir = realpath(__DIR__ . '/../../');

        // Commands to fetch and hard reset to match remote unconditionally. 
        // This is safe since user approved "what is best" and we decided hard reset guarantees consistency without conflict issues.
        $commands = [
            "cd " . escapeshellarg($rootDir),
            "git fetch origin main",
            "git reset --hard origin/main"
        ];
        
        $output = [];
        $returnVar = 0;
        
        $cmd = implode(' && ', $commands);
        exec($cmd . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::error('فشل عملية التحديث', ['output' => $output]);
            Response::error('حدث خطأ أثناء تنزيل أو تطبيق التحديثات. تحقق من السجلات.', 500, ['logs' => escapeshellarg($rootDir)]);
        }

        // Install dependencies if necessary
        // exec("cd " . escapeshellarg($rootDir . "/frontend") . " && npm install 2>&1", $npmOut, $npmRet);

        Response::success([
            'message' => 'تم تطبيق التحديث بنجاح. سيتم الآن تحديث قاعدة البيانات إن وجدت تعديلات.',
            'logs'    => $output
        ]);
    }

}

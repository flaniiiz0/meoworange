<?php
/**
 * =========================================================================
 * meoworange Boutique - XAMPP Backend API (PHP + MySQL / MariaDB)
 * =========================================================================
 * วิธีใช้งานบน XAMPP Control Panel:
 * 1. กด Start ที่ช่อง Apache และ MySQL ใน XAMPP Control Panel
 * 2. นำไฟล์นี้ (api.php) และไฟล์หน้าเว็บ (ตั้งชื่อเป็น index.html)
 *    ไปวางไว้ในโฟลเดอร์ C:\xampp\htdocs\meoworange\
 * 3. เปิดเบราว์เซอร์ไปที่ http://localhost/meoworange/
 *    (ระบบจะสร้างฐานข้อมูล meoworange_db และตาราง store_keys ให้อัตโนมัติ)
 * =========================================================================
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =========================================================================
// 1. ตั้งค่าการเชื่อมต่อ MySQL บน XAMPP (ค่าเริ่มต้นของ XAMPP คือ root / รหัสว่าง)
// =========================================================================
$dbHost = '127.0.0.1';
$dbPort = '3306';
$dbUser = 'root';
$dbPass = '';
$dbName = 'meoworange_db';

try {
    // เชื่อมต่อ MySQL Server และสร้างฐานข้อมูลอัตโนมัติหากยังไม่มี
    $dsnServer = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
    $pdo = new PDO($dsnServer, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // สร้างตารางเก็บข้อมูลร้านค้าแบบ Key-Value (รองรับรูปภาพขนาดใหญ่ด้วย LONGTEXT)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `store_keys` (
            `store_key` VARCHAR(120) NOT NULL PRIMARY KEY,
            `store_value` LONGTEXT NOT NULL,
            `updated_at` BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เชื่อมต่อ MySQL บน XAMPP ไม่สำเร็จ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// 2. จัดการคำสั่ง API (load_all / save_key / save_batch)
// =========================================================================
$action = $_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'load_all' : '');

try {
    // ดึงข้อมูลทั้งหมดจากฐานข้อมูล MySQL ส่งกลับไปที่หน้าเว็บ
    if ($action === 'load_all') {
        $stmt = $pdo->query("SELECT `store_key`, `store_value`, `updated_at` FROM `store_keys`");
        $rows = $stmt->fetchAll();

        $data = [];
        $latestUpdate = 0;
        foreach ($rows as $row) {
            $data[$row['store_key']] = $row['store_value'];
            $ts = (int)$row['updated_at'];
            if ($ts > $latestUpdate) {
                $latestUpdate = $ts;
            }
        }

        echo json_encode([
            'ok' => true,
            'database' => $dbName,
            'latestUpdate' => $latestUpdate,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // อ่านข้อมูล JSON จาก POST Body
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'รูปแบบข้อมูล JSON ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // บันทึกข้อมูลทีละหมวด (เช่น เพิ่มไอดีเกม, เพิ่มเมล, ส่งสลิป, แชต, รีวิว)
    if ($action === 'save_key') {
        $key = trim((string)($payload['key'] ?? ''));
        $value = $payload['value'] ?? null;

        if ($key === '' || $value === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ไม่พบ key หรือ value ที่ต้องการบันทึก'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $serializedValue = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        $nowMs = (int)round(microtime(true) * 1000);

        $stmt = $pdo->prepare("
            INSERT INTO `store_keys` (`store_key`, `store_value`, `updated_at`)
            VALUES (:k, :v, :t)
            ON DUPLICATE KEY UPDATE
                `store_value` = VALUES(`store_value`),
                `updated_at` = VALUES(`updated_at`)
        ");
        $stmt->execute([
            ':k' => $key,
            ':v' => $serializedValue,
            ':t' => $nowMs
        ]);

        echo json_encode([
            'ok' => true,
            'key' => $key,
            'updatedAt' => $nowMs
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // บันทึกข้อมูลทุกหมวดพร้อมกัน (Batch Sync)
    if ($action === 'save_batch') {
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ไม่พบรายการ items สำหรับบันทึก'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $nowMs = (int)round(microtime(true) * 1000);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO `store_keys` (`store_key`, `store_value`, `updated_at`)
            VALUES (:k, :v, :t)
            ON DUPLICATE KEY UPDATE
                `store_value` = VALUES(`store_value`),
                `updated_at` = VALUES(`updated_at`)
        ");

        foreach ($items as $k => $v) {
            $cleanKey = trim((string)$k);
            if ($cleanKey === '') continue;
            $serializedVal = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $stmt->execute([
                ':k' => $cleanKey,
                ':v' => $serializedVal,
                ':t' => $nowMs
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'ok' => true,
            'count' => count($items),
            'updatedAt' => $nowMs
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'ไม่พบคำสั่ง action ที่ระบุ'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาดฝั่งหลังบ้าน PHP: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
<?php
/**
 * ============================================================================
 * Save Page API (savepagetolib.php)
 * ============================================================================
 * รองรับการรับข้อมูล AJAX POST แบบ multipart/form-data
 * บันทึกโค้ด HTML (Gzip) และภาพหน้าจอ (PNG) ลงในฐานข้อมูล MySQL
 * หากยังไม่มีตาราง MyProjectCode ระบบจะสร้างให้อัตโนมัติ
 */

// 1. ตั้งค่า CORS เพื่ออนุญาตให้ยิง AJAX มาจากโดเมนอื่นหรือ file:// ได้
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// จัดการกรณี Preflight request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed. Use POST."]);
    exit();
}

// 2. ตั้งค่าการเชื่อมต่อฐานข้อมูล MySQL (กรุณาปรับเปลี่ยนข้อมูลให้ตรงกับ Server ของคุณ)
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'your_database_name'; // *** เปลี่ยนเป็นชื่อฐานข้อมูลของคุณ ***

try {
    // เชื่อมต่อ MySQL ด้วย PDO
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 3. ตรวจสอบและสร้างฐานข้อมูลหากยังไม่มี
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // 4. ตรวจสอบและสร้างตาราง MyProjectCode หากยังไม่มี
    // คอลัมน์ zipped_html และ screenshot เก็บค่าเป็น LONGBLOB (Binary Data)
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS `MyProjectCode` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `file_name` VARCHAR(255) NOT NULL,
            `path` VARCHAR(255) NOT NULL,
            `url` VARCHAR(512) NOT NULL,
            `zipped_html` LONGBLOB NULL,
            `screenshot` LONGBLOB NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_url` (`url`(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($createTableSQL);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database connection/initialization failed: " . $e->getMessage()
    ]);
    exit();
}

// 5. รับค่าจาก AJAX (FormData)
$fileName = isset($_POST['fileName']) ? trim($_POST['fileName']) : '';
$path     = isset($_POST['path']) ? trim($_POST['path']) : '';
$url      = isset($_POST['url']) ? trim($_POST['url']) : '';

// ตรวจสอบข้อมูลเบื้องต้น
if (empty($fileName) || empty($url)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields (fileName, url)"]);
    exit();
}

// 6. ดึงข้อมูลไฟล์ไบนารีที่อัปโหลด (Zipped HTML และ Screenshot)
$zippedHtmlBlob = null;
$screenshotBlob = null;

// ดึงข้อมูล Zipped HTML (.gz)
if (isset($_FILES['zippedHtml']) && $_FILES['zippedHtml']['error'] === UPLOAD_ERR_OK) {
    $zippedHtmlBlob = file_get_contents($_FILES['zippedHtml']['tmp_name']);
}

// ดึงข้อมูล Screenshot (.png)
if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
    $screenshotBlob = file_get_contents($_FILES['screenshot']['tmp_name']);
}

try {
    // 7. ตรวจสอบว่าหน้าเว็บนี้ (เช็คจาก url) เคยถูกบันทึกไว้หรือยัง
    $stmt = $pdo->prepare("SELECT `id`, `screenshot` FROM `MyProjectCode` WHERE `url` = :url LIMIT 1");
    $stmt->execute([':url' => $url]);
    $existingRecord = $stmt->fetch();

    if ($existingRecord) {
        // --- อัปเดตข้อมูลเดิม ---
        $recordId = $existingRecord['id'];
        
        // ถ้าไม่มีภาพใหม่ส่งมา ให้ใช้ภาพเดิมในฐานข้อมูล (ไม่เขียนทับเป็นค่าว่าง)
        if ($screenshotBlob === null) {
            $screenshotBlob = $existingRecord['screenshot'];
        }

        $updateSQL = "
            UPDATE `MyProjectCode` 
            SET `file_name` = :file_name,
                `path` = :path,
                `zipped_html` = :zipped_html,
                `screenshot` = :screenshot
            WHERE `id` = :id
        ";
        
        $updateStmt = $pdo->prepare($updateSQL);
        $updateStmt->bindParam(':file_name', $fileName);
        $updateStmt->bindParam(':path', $path);
        $updateStmt->bindParam(':zipped_html', $zippedHtmlBlob, PDO::PARAM_LOB);
        $updateStmt->bindParam(':screenshot', $screenshotBlob, PDO::PARAM_LOB);
        $updateStmt->bindParam(':id', $recordId, PDO::PARAM_INT);
        $updateStmt->execute();

        echo json_encode([
            "status" => "success",
            "action" => "update",
            "message" => "Page update successful",
            "id" => $recordId
        ]);

    } else {
        // --- บันทึกข้อมูลใหม่ ---
        $insertSQL = "
            INSERT INTO `MyProjectCode` (`file_name`, `path`, `url`, `zipped_html`, `screenshot`) 
            VALUES (:file_name, :path, :url, :zipped_html, :screenshot)
        ";
        
        $insertStmt = $pdo->prepare($insertSQL);
        $insertStmt->bindParam(':file_name', $fileName);
        $insertStmt->bindParam(':path', $path);
        $insertStmt->bindParam(':url', $url);
        $insertStmt->bindParam(':zipped_html', $zippedHtmlBlob, PDO::PARAM_LOB);
        $insertStmt->bindParam(':screenshot', $screenshotBlob, PDO::PARAM_LOB);
        $insertStmt->execute();

        $newId = $pdo->lastInsertId();

        echo json_encode([
            "status" => "success",
            "action" => "insert",
            "message" => "Page save successful",
            "id" => $newId
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Database query failed: " . $e->getMessage()
    ]);
}

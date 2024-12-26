<?php
include 'sensitive_data.php';

$host = DB_HOST;
$db = DB_NAME;
$user = DB_USER;
$pass = DB_PASS;
$dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo "Veritabanı ile bağlantı kurulamadı: " . $e->getMessage();
    exit();
}

// Tokeni doğrula
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    $stmt = $pdo->prepare("SELECT new_login_email FROM settings WHERE email_confirmation_token = :token AND email_is_confirmed = 0");
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if ($row) {
        $newEmail = $row['new_login_email'];
        $updateStmt = $pdo->prepare("
            UPDATE settings 
            SET login_email = :new_email, email_is_confirmed = 1, email_confirmation_token = NULL, new_login_email = NULL 
            WHERE id = 1
        ");
        $updateStmt->execute(['new_email' => $newEmail]);

        echo "E-Mailiniz onaylanmıştır.";
    } else {
        echo "Geçersiz veya süresi dolmuş token.";
    }
} else {
    echo "Token eksik.";
}
?>

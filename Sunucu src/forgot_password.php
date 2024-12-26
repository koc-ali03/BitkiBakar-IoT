<?php
require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/Exception.php";
require "PHPMailer/src/SMTP.php";

include 'sensitive_data.php';

session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    echo "Veritabanı ile bağlantı kurulamadı:" . $e->getMessage();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Email adres uyumluluğunu kontrol eder
        $stmt = $pdo->prepare("SELECT id FROM settings WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Reset işlemi için token üretir
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d H:i:s', strtotime('+5 hour'));

            // Tokeni veritabanına kaydeder
            $updateStmt = $pdo->prepare("
                UPDATE settings 
                SET password_reset_token = :token, password_reset_expires = :expires 
                WHERE email = :email
            ");
            $updateStmt->execute(['token' => $token, 'expires' => $expires, 'email' => $email]);

            $resetLink = "http://cloud.aliko.cc/reset_password.php?token=$token";

            // PHPMailer ile reset linkini gönderir
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->CharSet = 'UTF-8';
                $mail->Host = 'smtp.zoho.eu';
                $mail->SMTPAuth = true;
                $mail->Username = 'bitkibakar@aliko.cc';
                $mail->Password = MAIL_SIFRE;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom('bitkibakar@aliko.cc', 'BitkiBakar Bildirim');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'BitkiBakar Şifre Yenileme';
                $mail->Body = "Lütfen linke tıklayarak şifrenizi yenileyin: <a href=\"$resetLink\">$resetLink</a>";

                $mail->send();
                $successMessage = "A password reset link has been sent to your email.";
            } catch (Exception $e) {
                $errorMessage = "E-mail gönderme işlemi başarısız: " . $mail->ErrorInfo;
            }
        } else {
            $errorMessage = "Böyle bir E-Mail adresi bulunamadı.";
        }
    } else {
        $errorMessage = "E-Mail adresi geçersiz.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BitkiBakar Şifremi Unuttum</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-dark text-white">Şifremi Unuttum</div>
                    <div class="card-body">
                        <?php if (isset($successMessage)): ?>
                            <div class="alert alert-success"><?= $successMessage ?></div>
                        <?php elseif (isset($errorMessage)): ?>
                            <div class="alert alert-danger"><?= $errorMessage ?></div>
                        <?php endif; ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="email" class="form-label">E-Mail Adresinizi Girin :</label>
                                <input type="email" class="form-control" id="email" name="email" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Yenileme Linki Gönder</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

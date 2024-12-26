<?php
require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/Exception.php";
require "PHPMailer/src/SMTP.php";

include 'sensitive_data.php';

date_default_timezone_set('Europe/Istanbul'); // Replace with your correct timezone

// Login işlemi
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

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
    echo "Veritabanı ile bağlantı kurulamadı: " . $e->getMessage();
    exit();
}

// Ayarları alır
$query = "SELECT email, login_email, password_hash, email_is_confirmed FROM settings WHERE id = 1";
$settings = $pdo->query($query)->fetch();
$email = $settings['email'] ?? '';
$login_email = $settings['login_email'] ?? $email;
$email_is_confirmed = $settings['email_is_confirmed'] ?? false;

// Sınır değerleri alır
$thresholdQuery = "SELECT * FROM thresholds WHERE id = 1";
$thresholds = $pdo->query($thresholdQuery)->fetch();
$temperature_high = $thresholds['temperature_high'] ?? '';
$temperature_low = $thresholds['temperature_low'] ?? '';
$humidity_high = $thresholds['humidity_high'] ?? '';
$humidity_low = $thresholds['humidity_low'] ?? '';
$soil_moisture_high = $thresholds['soil_moisture_high'] ?? '';
$soil_moisture_low = $thresholds['soil_moisture_low'] ?? '';
$gas_high = $thresholds['gas_high'] ?? '';
$gas_low = $thresholds['gas_low'] ?? '';
$light_high = $thresholds['light_high'] ?? '';
$light_low = $thresholds['light_low'] ?? '';

// Form gönderme işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bildirimler
    if (isset($_POST['email'])) {
        $newEmail = $_POST['email'];
        if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $updateStmt = $pdo->prepare("UPDATE settings SET email = :email WHERE id = 1");
            $updateStmt->execute(['email' => $newEmail]);
            $email = $newEmail;
            $successMessage = "Ayarlar başarıyla kaydedildi.";
        } else {
            $errorMessage = "E-Mail adresi hatalı.";
        }
    }

    // Sınır değerlerinin güncellenmesi
    if (isset($_POST['temperature_high'], $_POST['temperature_low'], $_POST['humidity_high'], $_POST['humidity_low'], $_POST['soil_moisture_high'], $_POST['soil_moisture_low'], $_POST['gas_high'], $_POST['gas_low'], $_POST['light_high'], $_POST['light_low'])) {
        $newTemperatureHigh = $_POST['temperature_high'];
        $newTemperatureLow = $_POST['temperature_low'];
        $newHumidityHigh = $_POST['humidity_high'];
        $newHumidityLow = $_POST['humidity_low'];
        $newSoilMoistureHigh = $_POST['soil_moisture_high'];
        $newSoilMoistureLow = $_POST['soil_moisture_low'];
        $newGasHigh = $_POST['gas_high'];
        $newGasLow = $_POST['gas_low'];
        $newLightHigh = $_POST['light_high'];
        $newLightLow = $_POST['light_low'];

        if (
            is_numeric($newTemperatureHigh) && is_numeric($newTemperatureLow) &&
            is_numeric($newHumidityHigh) && is_numeric($newHumidityLow) &&
            is_numeric($newSoilMoistureHigh) && is_numeric($newSoilMoistureLow) &&
            is_numeric($newGasHigh) && is_numeric($newGasLow) &&
            is_numeric($newLightHigh) && is_numeric($newLightLow)
        ) {
            $thresholdUpdateStmt = $pdo->prepare("
                UPDATE thresholds 
                SET temperature_high = :temperature_high, temperature_low = :temperature_low, 
                    humidity_high = :humidity_high, humidity_low = :humidity_low,
                    soil_moisture_high = :soil_moisture_high, soil_moisture_low = :soil_moisture_low, 
                    gas_high = :gas_high, gas_low = :gas_low, 
                    light_high = :light_high, light_low = :light_low
                WHERE id = 1
            ");
            $thresholdUpdateStmt->execute([
                'temperature_high' => $newTemperatureHigh,
                'temperature_low' => $newTemperatureLow,
                'humidity_high' => $newHumidityHigh,
                'humidity_low' => $newHumidityLow,
                'soil_moisture_high' => $newSoilMoistureHigh,
                'soil_moisture_low' => $newSoilMoistureLow,
                'gas_high' => $newGasHigh,
                'gas_low' => $newGasLow,
                'light_high' => $newLightHigh,
                'light_low' => $newLightLow,
            ]);
            $successMessage = "Ayarlar başarıyla kaydedildi.";
        } else {
            $errorMessage = "Hata, sınır değerleri yanlış girilmiş.";
        }
    }

    // Login emaili ve şifre güncelleme
    if (isset($_POST['login_email'])) {
        $newLoginEmail = $_POST['login_email'];
        if (filter_var($newLoginEmail, FILTER_VALIDATE_EMAIL)) {
            if ($newLoginEmail !== $login_email) {
                $token = bin2hex(random_bytes(16));
                $updateStmt = $pdo->prepare("
                    UPDATE settings 
                    SET new_login_email = :new_login_email, email_confirmation_token = :token, email_is_confirmed = 0 
                    WHERE id = 1
                ");
                $updateStmt->execute([
                    'new_login_email' => $newLoginEmail,
                    'token' => $token,
                ]);

                $confirmationLink = "http://cloud.aliko.cc/confirm_email.php?token=$token";

                // PHPMailer ile email gönderir
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
                    $mail->addAddress($newLoginEmail);

                    $mail->isHTML(true);
                    $mail->Subject = 'BitkiBakar E-Mail Adresi Onaylama';
                    $mail->Body = "Lütfen linke tıklayarak e-mail adresinizi onaylayın: <a href=\"$confirmationLink\">$confirmationLink</a>";

                    $mail->send();
                    $successMessage = "Yeni E-Mail (Giriş) adresinize onay linki gönderildi.";
                } catch (Exception $e) {
                    $errorMessage = "E-mail gönderme işlemi başarısız: " . $mail->ErrorInfo;
                }
            } else {
                $successMessage = "Ayarlar başarıyla kaydedildi.";
            }
        } else {
            $errorMessage = "E-Mail adresi hatalı.";
        }
    }

    if (!empty($_POST['password'])) {
        $newPassword = $_POST['password'];
        $passwordHash = hash('sha256', $newPassword);
        $updatePasswordStmt = $pdo->prepare("UPDATE settings SET password_hash = :password_hash WHERE id = 1");
        $updatePasswordStmt->execute(['password_hash' => $passwordHash]);
        $successMessage = "Ayarlar başarıyla kaydedildi.";
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BitkiBakar Ayarlar</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css">
    <style>
        body {
            padding-top: 70px;
        }
    </style>
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">BitkiBakar</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="filter.php">Veri Bul</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="settings.php">Ayarlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Çıkış Yap</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="card-title mb-0">Ayarlar</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($successMessage)): ?>
                            <div class="alert alert-success"> <?= $successMessage ?> </div>
                        <?php endif; ?>

                        <?php if (isset($errorMessage)): ?>
                            <div class="alert alert-danger"> <?= $errorMessage ?> </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="email" class="form-label">E-Mail (Bildirimler İçin) :</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="temperature_high" class="form-label">Sıcaklık Üst Sınırı :</label>
                                <input type="number" class="form-control" id="temperature_high" name="temperature_high" value="<?= htmlspecialchars($temperature_high) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="temperature_low" class="form-label">Sıcaklık Alt Sınırı :</label>
                                <input type="number" class="form-control" id="temperature_low" name="temperature_low" value="<?= htmlspecialchars($temperature_low) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="humidity_high" class="form-label">Nem (Hava) Üst Sınırı :</label>
                                <input type="number" class="form-control" id="humidity_high" name="humidity_high" value="<?= htmlspecialchars($humidity_high) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="humidity_low" class="form-label">Nem (Hava) Alt Sınırı :</label>
                                <input type="number" class="form-control" id="humidity_low" name="humidity_low" value="<?= htmlspecialchars($humidity_low) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="soil_moisture_high" class="form-label">Nem (Toprak) Üst Sınırı :</label>
                                <input type="number" class="form-control" id="soil_moisture_high" name="soil_moisture_high" value="<?= htmlspecialchars($soil_moisture_high) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="soil_moisture_low" class="form-label">Nem (Toprak) Alt Sınırı :</label>
                                <input type="number" class="form-control" id="soil_moisture_low" name="soil_moisture_low" value="<?= htmlspecialchars($soil_moisture_low) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="light_high" class="form-label">Işık Üst Sınırı:</label>
                                <input type="number" class="form-control" id="light_high" name="light_high" value="<?= htmlspecialchars($light_high) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="light_low" class="form-label">Işık Alt Sınırı:</label>
                                <input type="number" class="form-control" id="light_low" name="light_low" value="<?= htmlspecialchars($light_low) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="gas_high" class="form-label">Gaz Üst Sınırı :</label>
                                <input type="number" class="form-control" id="gas_high" name="gas_high" value="<?= htmlspecialchars($gas_high) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="gas_low" class="form-label">Gaz Alt Sınırı:</label>
                                <input type="number" class="form-control" id="gas_low" name="gas_low" value="<?= htmlspecialchars($gas_low) ?>" required>
                            </div>
                            </br>

                            <div class="mb-3">
                                <label for="login_email" class="form-label">E-Mail (Giriş İçin) :</label>
                                <input type="email" class="form-control" id="login_email" name="login_email" value="<?= htmlspecialchars($login_email) ?>" required>
                                <small class="text-muted">
                                    <?= $email_is_confirmed ? "" : "Lütfen E-Mail adresinizi onaylayınız."; ?>
                                </small>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Şifreyi Değiştir :</label>
                                <input type="password" class="form-control" id="password" name="password">
                                <small class="text-muted">Değiştirmek istemiyorsanız boş bırakın.</small>
                            </div>
                            <button type="submit" class="btn btn-primary">Kaydet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

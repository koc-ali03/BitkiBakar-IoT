<?php
require "PHPMailer/src/PHPMailer.php";
require "PHPMailer/src/Exception.php";
require "PHPMailer/src/SMTP.php";

include 'sensitive_data.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Europe/Istanbul'); // Replace with your correct timezone

// API key doğrulanır (sensitive_data.php içinde)
if ($_SERVER['HTTP_API_KEY'] !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

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
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if ($data) {
    $entry = [
        'temperature' => $data['temperature'] ?? null,
        'humidity' => $data['humidity'] ?? null,
        'soil_moisture' => $data['soil_moisture'] ?? null,
        'ldr' => $data['ldr'] ?? null,
        'rain_sensor' => $data['rain_sensor'] ?? null,
        'gas_sensor' => $data['gas_sensor'] ?? null,
    ];

    // Veriyi veritabanına ekle
    $stmt = $pdo->prepare("
        INSERT INTO readings (time, temperature, humidity, soil_moisture, ldr, rain_sensor, gas_sensor)
        VALUES (NOW(), :temperature, :humidity, :soil_moisture, :ldr, :rain_sensor, :gas_sensor)
    ");
    
    $stmt->execute($entry);

    // Herhangi bir değer belirli bir sınırı aşıyor mu, aşıyorsa bildirim gönderir
    checkThresholds($data, $pdo);

    echo json_encode(['message' => 'Veri alindi ve kaydedildi']);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Veri hatasi']);
}

// Bildirim emaili gönderir
function sendEmailNotification($pdo, $subject, $message) {
    $mail = new PHPMailer(true);

    $stmt = $pdo->query("SELECT email FROM settings WHERE id = 1");
    $recipientEmail = $stmt->fetchColumn();

    if (!$recipientEmail) {
        error_log("Bildirim E-Mail adresine ulasilamadi.");
        return;
    }

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
        $mail->addAddress($recipientEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
    }
}

// Herhangi bir değer belirli bir sınırı aşıyor mu, aşıyorsa bildirim gönderir
function checkThresholds($data, $pdo) {
    // Veritabanından sınır değerlerini alır
    $stmt = $pdo->query("SELECT * FROM thresholds WHERE id = 1");
    $thresholds = $stmt->fetch();

    $temperatureHigh = $thresholds['temperature_high'];
    $temperatureLow = $thresholds['temperature_low'];
    $humidityHigh = $thresholds['humidity_high'];
    $humidityLow = $thresholds['humidity_low'];
    $soilMoistureHigh = $thresholds['soil_moisture_high'];
    $soilMoistureLow = $thresholds['soil_moisture_low'];
    $gasHigh = $thresholds['gas_high'];
    $gasLow = $thresholds['gas_low'];
    $lightHigh = $thresholds['light_high'];
    $lightLow = $thresholds['light_low'];

    // En son ne zaman bildirim gönderilmiş
    $stmt = $pdo->query("SELECT temp_last_notif, humidity_last_notif, soil_last_notif, light_last_notif, gas_last_notif, rain_last_notif FROM thresholds WHERE id = 1");
    $lastNotifs = $stmt->fetch();

    // Bekleme süresi (cooldown)
    $cooldownPeriod = new DateInterval('PT3H'); // 3 saat

    if ($data['temperature'] > $temperatureHigh) {
        checkAndSendNotification($pdo, 'temp', 'Yüksek Sıcaklık Uyarısı', "{$data['temperature']} °C", $cooldownPeriod);
    } elseif ($data['temperature'] < $temperatureLow) {
        checkAndSendNotification($pdo, 'temp', 'Düşük Sıcaklık Uyarısı', "{$data['temperature']} °C", $cooldownPeriod);
    }

    if ($data['humidity'] > $humidityHigh) {
        checkAndSendNotification($pdo, 'humidity', 'Yüksek Nem (Hava) Uyarısı', "{$data['humidity']} %", $cooldownPeriod);
    } elseif ($data['humidity'] < $humidityLow) {
        checkAndSendNotification($pdo, 'humidity', 'Düşük Nem (Hava) Uyarısı', "{$data['humidity']} %", $cooldownPeriod);
    }

    if ($data['soil_moisture'] > $soilMoistureHigh) {
        checkAndSendNotification($pdo, 'soil', 'Yüksek Nem (Toprak) Uyarısı', "{data['soil_moisture']} %", $cooldownPeriod);
    } elseif ($data['soil_moisture'] < $soilMoistureLow) {
        checkAndSendNotification($pdo, 'soil', 'Düşük Nem (Toprak) Uyarısı', "{$data['soil_moisture']} %", $cooldownPeriod);
    }

    if ($data['gas_sensor'] > $gasHigh) {
        checkAndSendNotification($pdo, 'gas', 'Yüksek Gaz Yoğunluğu Uyarısı', "{$data['gas_sensor']} PPM", $cooldownPeriod);
    } elseif ($data['gas_sensor'] < $gasLow) {
        checkAndSendNotification($pdo, 'gas', 'Düşük Gaz Yoğunluğu Uyarısı', "{$data['gas_sensor']} PPM", $cooldownPeriod);
    }

    if ($data['ldr'] > $lightHigh) {
        checkAndSendNotification($pdo, 'light', 'Yüksek Işık Yoğunluğu Uyarısı', "{$data['ldr']} lux", $cooldownPeriod);
    } elseif ($data['ldr'] < $lightLow) {
        checkAndSendNotification($pdo, 'light', 'Düşük Işık Yoğunluğu Uyarısı', "{$data['ldr']} lux", $cooldownPeriod);
    }

    if ($data['rain_sensor'] == 1) checkAndSendNotification($pdo, 'rain', 'Yağmur Durumu', 'Yağmur Yağıyor', $cooldownPeriod);
}

// E-Mail göndermeden önce bekleme süresi (cooldown) dolmuş mu kontrol eder, sıkıntı yoksa bildirim gönderilir
function checkAndSendNotification($pdo, $sensor, $subject, $value, $cooldownPeriod) {
    $stmt = $pdo->query("SELECT temp_last_notif, humidity_last_notif, soil_last_notif, light_last_notif, gas_last_notif, rain_last_notif FROM thresholds WHERE id = 1");
    $lastNotifs = $stmt->fetch();

    // Calculate cooldown period in seconds
    $cooldownPeriodSeconds = ($cooldownPeriod->days * 24 * 60 * 60) 
                            + ($cooldownPeriod->h * 60 * 60) 
                            + ($cooldownPeriod->i * 60) 
                            + $cooldownPeriod->s;

    // Eğer ilk defa bildirim gönderiliyorsa (ilk gönderme tarihi NULL) tabloya bu an atanır ve bekleme süresi bu istek için sıfıra çekilir
    if (is_null($lastNotifs["{$sensor}_last_notif"])) {
        $stmt = $pdo->prepare("UPDATE thresholds SET {$sensor}_last_notif = NOW() WHERE id = 1");
        $stmt->execute();

        $cooldownPeriodSeconds = 0;
    }

    $lastNotifDate = new DateTime($lastNotifs["{$sensor}_last_notif"]);
    $currentDate = new DateTime();
    
    $lastNotifTimestamp = $lastNotifDate->getTimestamp();
    $currentTimestamp = $currentDate->getTimestamp();

    // Bekleme süresi dolmuş mu kontrol eder
    if (($currentTimestamp - $lastNotifTimestamp) >= $cooldownPeriodSeconds) {
        sendEmailNotification($pdo, $subject, "$subject: $value");

        // Bekleme süresini tekrardan oluşturur
        $stmt = $pdo->prepare("UPDATE thresholds SET {$sensor}_last_notif = NOW() WHERE id = 1");
        $stmt->execute();

        echo "Bildirim gönderildi: $subject.";
    } else {
        echo "'$subject' bildirimi gönderilemedi, bu veri için bekleme süresi dolmadı.\n";
    }
}

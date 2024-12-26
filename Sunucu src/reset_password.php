<?php
include 'sensitive_data.php';

session_start();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['password'] ?? '';

    if ($token && $newPassword) {
        // Tokenin doğruluğunu kontrol eder
        $stmt = $pdo->prepare("
            SELECT id 
            FROM settings 
            WHERE password_reset_token = :token 
              AND password_reset_expires > NOW()
        ");
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if ($user) {
            // Şifre hash işlemi
            $passwordHash = hash('sha256', $newPassword);

            // Şifreyi güncelle, tokeni sıfırla
            $updateStmt = $pdo->prepare("
                UPDATE settings 
                SET password_hash = :password_hash, password_reset_token = NULL, password_reset_expires = NULL 
                WHERE id = :id
            ");
            $updateStmt->execute(['password_hash' => $passwordHash, 'id' => $user['id']]);

            $successMessage = "Şifreniz başarıyla yenilendi. Yeni şifreniz ile giriş yapabilirsiniz.";
        } else {
            $errorMessage = "Geçersiz veya süresi dolmuş token.";
        }
    } else {
        $errorMessage = "Lütfen geçerli bir token ve şifre giriniz.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BitkiBakar Şifre Yenileme</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-dark text-white">Şifre Yenileme</div>
                    <div class="card-body">
                        <?php if (isset($successMessage)): ?>
                            <div class="alert alert-success"><?= $successMessage ?></div>
                        <?php elseif (isset($errorMessage)): ?>
                            <div class="alert alert-danger"><?= $errorMessage ?></div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">

                            <div class="mb-3">
                                <label for="password" class="form-label">Yeni Şifre :</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Şifreyi Onayla</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


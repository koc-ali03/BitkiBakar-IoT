<?php
include 'sensitive_data.php';

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

date_default_timezone_set('Europe/Istanbul'); // Replace with your correct timezones

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

// Default date range (last 7 days) if no input
$startDate = $_GET['start_date'] ?? date('Y-m-d\TH:i', strtotime('-1 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d\TH:i');

// Fetch sensor data within the date and time range
$query = "
    SELECT * FROM readings 
    WHERE time BETWEEN :start_date AND :end_date
    ORDER BY time DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute([
    'start_date' => $startDate,
    'end_date' => $endDate
]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Separate data for each sensor
$labels = array_reverse(array_map(function ($entry) {
    return isset($entry['time']) ? date('Y-m-d H:i:s', strtotime($entry['time'])) : 'Invalid';
}, $data));

$temperature = array_reverse(array_column($data, 'temperature'));
$humidity = array_reverse(array_column($data, 'humidity'));
$soilMoisture = array_reverse(array_column($data, 'soil_moisture'));
$ldr = array_reverse(array_column($data, 'ldr'));
$rainSensor = array_reverse(array_column($data, 'rain_sensor'));
$gasSensor = array_reverse(array_column($data, 'gas_sensor'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BitkiBakar Veri Bul</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha3/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                        <a class="nav-link active" href="filter.php">Veri Bul</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">Ayarlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Çıkış Yap</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <form method="GET" class="mb-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="start_date" class="form-label">Başlangıç Tarihi ve Saati</label>
                    <input type="datetime-local" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($startDate))) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="end_date" class="form-label">Bitiş Tarihi ve Saati</label>
                    <input type="datetime-local" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($endDate))) ?>" required>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filtrele</button>
                </div>
            </div>
        </form>

        <div class="row gy-4">
            <div class="col-md-6">
                <h4 class="text-center">Sıcaklık (°C)</h4>
                <canvas id="temperatureChart"></canvas>
            </div>
            <div class="col-md-6">
                <h4 class="text-center">Havadaki Nem (%)</h4>
                <canvas id="humidityChart"></canvas>
            </div>
            <div class="col-md-6">
                <h4 class="text-center">Topraktaki Nem (%)</h4>
                <canvas id="soilMoistureChart"></canvas>
            </div>
            <div class="col-md-6">
                <h4 class="text-center">Işık Şiddeti</h4>
                <canvas id="ldrChart"></canvas>
            </div>
            <div class="col-md-6">
                <h4 class="text-center">Yağmur Durumu</h4>
                <canvas id="rainSensorChart"></canvas>
            </div>
            <div class="col-md-6">
                <h4 class="text-center">Gaz Yoğunluğu (PPM)</h4>
                <canvas id="gasSensorChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        const labels = <?php echo json_encode($labels); ?>;

        const createChart = (ctxId, label, data, color, yAxisLabel) => {
            const ctx = document.getElementById(ctxId).getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: label,
                        data: data,
                        borderColor: color,
                        borderWidth: 2,
                        fill: false,
                        pointRadius: 2
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${label}: ${context.raw} ${yAxisLabel}`;
                                }
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Tarih-Saat'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: yAxisLabel
                            }
                        }
                    }
                }
            });
        };

        const createStepChart = (ctxId, label, data) => {
            const ctx = document.getElementById(ctxId).getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: label,
                        data: data,
                        borderColor: 'rgba(153, 102, 255, 1)',
                        borderWidth: 2,
                        fill: false,
                        stepped: true,
                        pointRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw == 1 ? 'Yağmur Var' : 'Yağmur Yok';
                                }
                            }
                        },
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Tarih-Saat'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Yağmur Durumu'
                            },
                            ticks: {
                                callback: function(value) {
                                    return value == 1 ? 'Yağmur Var' : 'Yağmur Yok';
                                },
                                stepSize: 1
                            },
                            min: 0,
                            max: 1
                        }
                    }
                }
            });
        };

        createChart('temperatureChart', 'Sıcaklık', <?php echo json_encode($temperature); ?>, 'rgba(255, 99, 132, 1)', '°C');
        createChart('humidityChart', 'Nem (Hava)', <?php echo json_encode($humidity); ?>, 'rgba(54, 162, 235, 1)', '%');
        createChart('soilMoistureChart', 'Nem (Toprak)', <?php echo json_encode($soilMoisture); ?>, 'rgba(75, 192, 192, 1)', '%');
        createChart('ldrChart', 'Işık Şiddeti', <?php echo json_encode($ldr); ?>, 'rgba(255, 206, 86, 1)', 'Lux');
        createChart('gasSensorChart', 'Gaz Yoğunluğu', <?php echo json_encode($gasSensor); ?>, 'rgba(255, 159, 64, 1)', 'PPM');
        createStepChart('rainSensorChart', 'Yağmur Durumu', <?php echo json_encode($rainSensor); ?>);
    </script>
</body>
</html>

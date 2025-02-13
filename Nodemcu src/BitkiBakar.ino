#include <light_CD74HC4067.h>
#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <ArduinoJson.h>
#include <DHT.h>

#include "nodemcuConfig.h"

// Pin Definitions
#define DHTPIN D5
#define DHTTYPE DHT11
#define MUX_SIG_PIN A0 // Multiplexer analog pin
#define S0 D1          // D74HC4067 Kontrol pinleri
#define S1 D2
#define S2 D3
#define S3 D4
#define RAIN_PIN D6

DHT dht(DHTPIN, DHTTYPE);

// Multiplexer kanal seçme işlemi
CD74HC4067 mux(S0, S1, S2, S3);

WiFiClient client;

void setup() {
  Serial.begin(9600);

  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  while (WiFi.status() != WL_CONNECTED) {
    delay(1000);
    Serial.println("WiFi bağlantısı kuruluyor...");
  }
  Serial.println("WiFi bağlantısı kuruldu");

  dht.begin();

  pinMode(S0, OUTPUT);
  pinMode(S1, OUTPUT);
  pinMode(S2, OUTPUT);
  pinMode(S3, OUTPUT);

  pinMode(MUX_SIG_PIN, INPUT);

  pinMode(RAIN_PIN, INPUT);
}

void loop() {
  // DHT11
  float humidity = dht.readHumidity();
  float temperature = dht.readTemperature();

  // Toprak nem sensörü
  mux.channel(4);
  delay(20);
  int soilValue = analogRead(MUX_SIG_PIN);

  // Gaz sensörü
  mux.channel(7);
  delay(20);
  int gasValue = analogRead(MUX_SIG_PIN);

  // LDR
  mux.channel(11);
  delay(20);
  int ldrValue = analogRead(MUX_SIG_PIN);

  // Yağmur (su seviyesi) sensörü
  int rainValue = digitalRead(RAIN_PIN);

  // Önce verileri serial monitöre yazdır
  Serial.print("Sıcaklık: ");
  Serial.print(temperature);
  Serial.print(" *C, Nem (Hava): ");
  Serial.print(humidity);
  Serial.print(" %, Nem (Toprak): ");
  Serial.print(soilValue);
  Serial.print(", LDR: ");
  Serial.print(ldrValue);
  Serial.print(", Gaz: ");
  Serial.print(gasValue);
  Serial.print(", Yağmur: ");
  Serial.println(rainValue == LOW ? "Yağmur yok" : "Yağmur yağıyor");

  // Ardından sunucuya verileri gönder
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(client, SERVER_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("API-Key", API_KEY);

    StaticJsonDocument<200> json;
    json["temperature"] = temperature;
    json["humidity"] = humidity;
    json["soil_moisture"] = soilValue;
    json["ldr"] = ldrValue;
    json["gas_sensor"] = gasValue;
    json["rain_sensor"] = (rainValue == LOW ? 0 : 1);

    String requestBody;
    serializeJson(json, requestBody);

    // Http POST isteği
    int httpResponseCode = http.POST(requestBody);
    if (httpResponseCode > 0) {
      Serial.print("HTTP Response kodu: ");
      Serial.println(httpResponseCode);
    } else {
      Serial.print("HTTP Bağlantı hatası: ");
      Serial.println(httpResponseCode);
    }
    http.end();
  } else {
    Serial.println("WiFi not connected");
  }

  delay(5000); // 5 saniye bekle
}

<?php
/**
 * Plugin Name: Konsol Connector
 * Plugin URI: https://wckonsol.com
 * Description: Konsol'un WordPress/WooCommerce çekirdek REST API'sinin yapamadığı işleri açar — Yoast/Rank Math SEO alanları (hem WooCommerce ürünleri/kategorileri HEM WP Konsol blog yazıları için), günlük satış toplamları, güvenilir revizyon kontrolü. Katalog senkronizasyonu ve ürün görseli yayınlama bu eklenti OLMADAN da çalışır (bkz. readme.txt).
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Author: WP Konsol
 * License: GPLv2 or later
 * Text Domain: konsol-connector
 *
 * F3b — documentation/PHASES.md, konsol-connector v1. Bu depo, sonradan
 * ayrı bir GitHub hesabına/repoya taşınacak şekilde bilerek izole tutuldu
 * (monorepo'nun geri kalanına bağımlılığı yok, kendi başına bir WordPress
 * eklentisi).
 *
 * **2026-09-26 — WP Konsol'u da kapsayacak şekilde genişletildi**, sonra
 * (aynı gün) `wckonsol-*` iç isimlendirmesi (dosya/klasör/sınıflar/sabitler/
 * option key'ler/REST namespace) baştan sona `konsol-*`ye taşındı — plugin
 * artık iki ürünü de kapsadığı için "WC" öneki kafa karıştırıyordu (kullanıcı
 * bulgusu: "isimler hatalı değil mi?"). Önceden `Requires Plugins:
 * woocommerce` vardı — WooCommerce kurulu olmayan salt WP Konsol
 * sitelerinde eklentinin AKTİFLEŞMESİNİ bile engelliyordu, kaldırıldı.
 * `/posts/:id/seo` ucu (`class-rest-api.php`), WP Konsol'un blog yazıları
 * için aynı Yoast/Rank Math meta yazma mekanizmasını `/products/:id/seo`
 * ile aynı şekilde sağlıyor.
 *
 * Bu yeniden isimlendirme sırasında GERÇEK bir hata da bulundu ve düzeltildi:
 * varsayılan API adresi `api.wckonsol.com` idi — bu domain hiç DNS
 * çözümlenmiyor (canlıda asla var olmadı, muhtemelen hep yerel/staging
 * override ile test edilmişti). Tek gerçek API `api.wpkonsol.com` — artık
 * `KONSOL_API_BASE` bunu kullanıyor, ürüne göre ayrım yok (API zaten tek).
 */

if (!defined('ABSPATH')) exit;

define('KONSOL_VERSION', '0.3.0');
define('KONSOL_PLUGIN_FILE', __FILE__);
define('KONSOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
// Ayarlar ekranındaki "API base URL (advanced)" alanı bunu ezebilir —
// yerel/staging test için (ör. Docker Desktop'ta `http://host.docker.internal:4000/v1`).
// Tek gerçek API — WC Konsol ve WP Konsol aynı backend'i paylaşıyor, ayrı
// bir `api.wckonsol.com` hiç var olmadı (bkz. dosya başındaki 2026-09-26 notu).
if (!defined('KONSOL_API_BASE')) {
    define('KONSOL_API_BASE', 'https://api.wpkonsol.com/v1');
}
// "Eklenti önce" akışının (§3.1) kullanıcıyı yönlendirdiği yer — app'in
// kendisi, API değil. İki ayrı app olduğu için (`app.wckonsol.com` /
// `app.wpkonsol.com`) BU ikisi ürüne özel kalıyor — `Konsol_Pairing::
// app_base()` WooCommerce kurulu mu diye bakıp aralarında seçiyor.
if (!defined('KONSOL_APP_BASE_WCKONSOL')) {
    define('KONSOL_APP_BASE_WCKONSOL', 'https://app.wckonsol.com');
}
if (!defined('KONSOL_APP_BASE_WPKONSOL')) {
    define('KONSOL_APP_BASE_WPKONSOL', 'https://app.wpkonsol.com');
}

require_once KONSOL_PLUGIN_DIR . 'includes/class-keys.php';
require_once KONSOL_PLUGIN_DIR . 'includes/class-pairing.php';
require_once KONSOL_PLUGIN_DIR . 'includes/class-settings-page.php';
require_once KONSOL_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once KONSOL_PLUGIN_DIR . 'includes/class-login.php';

register_activation_hook(__FILE__, ['Konsol_Keys', 'ensure_keypair']);
register_activation_hook(__FILE__, ['Konsol_Pairing', 'schedule_heartbeat']);
register_deactivation_hook(__FILE__, ['Konsol_Pairing', 'unschedule_heartbeat']);
// F3b çıkış kriteri: "Eklenti kaldırıldığında bağlantı temiz biçimde
// kopuyor" — yalnızca ayarlar ekranındaki "Disconnect" butonuna değil,
// eklentinin doğrudan deaktive edilmesine de bağlı. `disconnect()` zaten
// hem API'yi bilgilendiriyor hem yerel durumu temizliyor (bkz.
// class-pairing.php) — eşleşmemişken çağrılırsa (`is_paired()` false)
// gereksiz bir ağ isteği atmadan sessizce çıkıyor.
register_deactivation_hook(__FILE__, function () {
    if (Konsol_Pairing::is_paired()) Konsol_Pairing::disconnect();
});

add_action('plugins_loaded', function () {
    Konsol_Keys::ensure_keypair();
    Konsol_Settings_Page::init();
    Konsol_REST_API::init();
    Konsol_Pairing::init();
    Konsol_Login::init();
});

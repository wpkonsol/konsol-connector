<?php
/**
 * Plugin Name: WC Konsol Connector
 * Plugin URI: https://wckonsol.com
 * Description: Konsol'un WordPress/WooCommerce çekirdek REST API'sinin yapamadığı işleri açar — Yoast/Rank Math SEO alanları (hem WooCommerce ürünleri/kategorileri HEM WP Konsol blog yazıları için), günlük satış toplamları, güvenilir revizyon kontrolü. Katalog senkronizasyonu ve ürün görseli yayınlama bu eklenti OLMADAN da çalışır (bkz. readme.txt).
 * Version: 0.2.0
 * Requires PHP: 7.4
 * Author: WP Konsol
 * License: GPLv2 or later
 * Text Domain: wckonsol-connector
 *
 * F3b — documentation/PHASES.md, konsol-connector v1. Bu depo, sonradan
 * ayrı bir GitHub hesabına/repoya taşınacak şekilde bilerek izole tutuldu
 * (monorepo'nun geri kalanına bağımlılığı yok, kendi başına bir WordPress
 * eklentisi).
 *
 * **2026-09-26 — WP Konsol'u da kapsayacak şekilde genişletildi.** Önceden
 * `Requires Plugins: woocommerce` vardı — bu, WooCommerce kurulu olmayan
 * salt WP Konsol sitelerinde eklentinin AKTİFLEŞMESİNİ bile engelliyordu,
 * kaldırıldı. Yeni `/posts/:id/seo` ucu (`class-rest-api.php`), WP Konsol'un
 * blog yazıları için aynı Yoast/Rank Math meta yazma mekanizmasını
 * `/products/:id/seo` ile aynı şekilde sağlıyor — ayrı bir
 * `wpkonsol-connector` planlanmıştı (bkz. eski PHASES.md F3b kararı), ama
 * pairing/keys/settings-page kodunun neredeyse birebir kopyası olacaktı;
 * tek, paylaşılan bir eklenti (repo adı zaten genel: `konsol-connector`,
 * `wckonsol-connector` değil) daha az tekrar, daha kolay bakım.
 */

if (!defined('ABSPATH')) exit;

define('WCKONSOL_VERSION', '0.2.0');
define('WCKONSOL_PLUGIN_FILE', __FILE__);
define('WCKONSOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
// Ayarlar ekranındaki "API base URL (advanced)" alanı bunu ezebilir —
// yerel/staging test için (ör. Docker Desktop'ta `http://host.docker.internal:4000/v1`).
if (!defined('WCKONSOL_API_BASE')) {
    define('WCKONSOL_API_BASE', 'https://api.wckonsol.com/v1');
}
// "Eklenti önce" akışının (§3.1) kullanıcıyı yönlendirdiği yer — app'in
// kendisi, API değil.
if (!defined('WCKONSOL_APP_BASE')) {
    define('WCKONSOL_APP_BASE', 'https://app.wckonsol.com');
}

require_once WCKONSOL_PLUGIN_DIR . 'includes/class-keys.php';
require_once WCKONSOL_PLUGIN_DIR . 'includes/class-pairing.php';
require_once WCKONSOL_PLUGIN_DIR . 'includes/class-settings-page.php';
require_once WCKONSOL_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once WCKONSOL_PLUGIN_DIR . 'includes/class-login.php';

register_activation_hook(__FILE__, ['WCKonsol_Keys', 'ensure_keypair']);
register_activation_hook(__FILE__, ['WCKonsol_Pairing', 'schedule_heartbeat']);
register_deactivation_hook(__FILE__, ['WCKonsol_Pairing', 'unschedule_heartbeat']);
// F3b çıkış kriteri: "Eklenti kaldırıldığında bağlantı temiz biçimde
// kopuyor" — yalnızca ayarlar ekranındaki "Disconnect" butonuna değil,
// eklentinin doğrudan deaktive edilmesine de bağlı. `disconnect()` zaten
// hem API'yi bilgilendiriyor hem yerel durumu temizliyor (bkz.
// class-pairing.php) — eşleşmemişken çağrılırsa (`is_paired()` false)
// gereksiz bir ağ isteği atmadan sessizce çıkıyor.
register_deactivation_hook(__FILE__, function () {
    if (WCKonsol_Pairing::is_paired()) WCKonsol_Pairing::disconnect();
});

add_action('plugins_loaded', function () {
    WCKonsol_Keys::ensure_keypair();
    WCKonsol_Settings_Page::init();
    WCKonsol_REST_API::init();
    WCKonsol_Pairing::init();
    WCKonsol_Login::init();
});

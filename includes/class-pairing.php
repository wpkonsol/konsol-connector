<?php
if (!defined('ABSPATH')) exit;

/**
 * Eşleştirme (pairing kodu → gerçek bağlantı) ve heartbeat. F3b, "WP Konsol
 * önce" akışı (SITE_IDENTITY_AND_TRANSFER.md §3) — kullanıcı önce
 * app.wckonsol.com'da bir kod üretir, o kodu burada girer.
 */
class WCKonsol_Pairing
{
    const OPT_API_BASE = 'wckonsol_api_base';
    const OPT_APP_BASE = 'wckonsol_app_base';
    const OPT_CONNECTION_TOKEN = 'wckonsol_connection_token';
    const OPT_PAIRED_SITE_ID = 'wckonsol_paired_site_id';
    const OPT_CLAIM_TOKEN = 'wckonsol_claim_token';
    const CRON_HOOK = 'wckonsol_heartbeat';
    const AJAX_ACTION = 'wckonsol_poll_claim';

    public static function init()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'send_heartbeat']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_poll_claim']);
    }

    public static function api_base(): string
    {
        $override = trim((string) get_option(self::OPT_API_BASE, ''));
        return $override !== '' ? rtrim($override, '/') : rtrim(WCKONSOL_API_BASE, '/');
    }

    public static function app_base(): string
    {
        $override = trim((string) get_option(self::OPT_APP_BASE, ''));
        return $override !== '' ? rtrim($override, '/') : rtrim(WCKONSOL_APP_BASE, '/');
    }

    public static function is_paired(): bool
    {
        return (bool) get_option(self::OPT_CONNECTION_TOKEN, '');
    }

    /** NestJS varsayılan olarak başarılı POST'larda 201 döner (yalnızca
     * `@HttpCode` ile ezilen uçlarda 200) — API tarafında hangisinin
     * döndüğünü ezbere bilmek yerine ikisini de kabul ediyoruz. Canlı
     * test bunun bir varsayım değil gerçek gereklilik olduğunu gösterdi:
     * `confirmClaim` 201 dönüyordu, eklenti yalnızca 200 kabul edince
     * teyit sessizce hiç kaydedilmiyordu. */
    private static function is_success($response): bool
    {
        $status = wp_remote_retrieve_response_code($response);
        return $status >= 200 && $status < 300;
    }

    public static function schedule_heartbeat()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // WP çekirdeğinin varsayılan aralıkları (hourly/twicedaily/daily)
            // eklenti bağlantısının canlılığını göstermek için çok seyrek —
            // 'wckonsol_five_minutes' özel bir aralık tanımlıyoruz.
            wp_schedule_event(time(), 'wckonsol_five_minutes', self::CRON_HOOK);
        }
    }

    public static function unschedule_heartbeat()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    /**
     * SITE_IDENTITY_AND_TRANSFER.md §3, adım 4/8: pairing kodu + imzalı
     * teyit tek istekte gidiyor (MVP basitleştirmesi — dokümandaki 4 ve 8.
     * adımlar burada birleşti, ayrı bir "sınırlı yetkili bağlantı kimliği"
     * ön adımı yok). İmza mesajı `pairingCode\nsiteUuid` — kodu ele
     * geçiren biri, bu kurulumun özel anahtarına sahip olmadıkça
     * eşleştirmeyi tamamlayamaz.
     */
    public static function confirm(string $pairing_code): array
    {
        $site_uuid = WCKonsol_Keys::site_uuid();
        $signature = WCKonsol_Keys::sign("{$pairing_code}\n{$site_uuid}");

        $body = [
            'pairingCode' => $pairing_code,
            'siteUuid' => $site_uuid,
            'publicKey' => WCKonsol_Keys::public_key_base64(),
            'signature' => $signature,
            'siteInfo' => [
                'url' => home_url(),
                'siteName' => get_bloginfo('name'),
                'wpVersion' => get_bloginfo('version'),
                'wcVersion' => defined('WC_VERSION') ? WC_VERSION : null,
                'phpVersion' => PHP_VERSION,
            ],
            'capabilities' => [
                'seoFields' => self::detect_seo_plugin() !== null,
                'mediaUpload' => true,
                'salesTotals' => class_exists('WooCommerce'),
                'seoPlugin' => self::detect_seo_plugin(),
            ],
        ];

        $response = wp_remote_post(self::api_base() . '/pairing/confirm', [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode($body),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }

        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!self::is_success($response)) {
            return ['ok' => false, 'error' => $data['message'] ?? "API {$status} döndü"];
        }

        update_option(self::OPT_CONNECTION_TOKEN, $data['connectionToken'], false);
        update_option(self::OPT_PAIRED_SITE_ID, $data['siteId'], true);

        return ['ok' => true];
    }

    /** F3b çıkış kriteri: "Bağlantı iptal edildiğinde kendini devre dışı
     * bırakma" — önce API'ye imzalı bir bildirim gönderiyor (best-effort;
     * API'ye ulaşılamasa bile yerel durum temizleniyor, kullanıcı burada
     * takılı kalmasın). */
    public static function disconnect()
    {
        $site_uuid = WCKonsol_Keys::site_uuid();
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = wp_generate_password(16, false);
        $signature = WCKonsol_Keys::sign("{$site_uuid}\n{$timestamp}\n{$nonce}");

        wp_remote_post(self::api_base() . '/pairing/disconnect', [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode(['siteUuid' => $site_uuid, 'timestamp' => $timestamp, 'nonce' => $nonce, 'signature' => $signature]),
            'timeout' => 10,
        ]);

        delete_option(self::OPT_CONNECTION_TOKEN);
        delete_option(self::OPT_PAIRED_SITE_ID);
    }

    // ------------------------------------------------ "eklenti önce" (§3.1)

    /**
     * "Get started without an account yet" butonu — kendi `claimToken`ini
     * üretir, API'ye kaydeder (imzalı: `claimToken\nsiteUuid`), kullanıcıyı
     * `/claim/:token`e yönlendirecek URL'i döner. Ayarlar sayfası bu URL'i
     * yeni sekmede açıp AJAX polling'i başlatıyor.
     */
    public static function start_claim(): array
    {
        $claim_token = wp_generate_password(32, false);
        $site_uuid = WCKonsol_Keys::site_uuid();
        $signature = WCKonsol_Keys::sign("{$claim_token}\n{$site_uuid}");

        $response = wp_remote_post(self::api_base() . '/pairing/claim-tokens', [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode([
                'claimToken' => $claim_token,
                'siteUuid' => $site_uuid,
                'publicKey' => WCKonsol_Keys::public_key_base64(),
                'signature' => $signature,
                'siteInfo' => ['url' => home_url(), 'siteName' => get_bloginfo('name')],
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response) || !self::is_success($response)) {
            return ['ok' => false, 'error' => is_wp_error($response) ? $response->get_error_message() : 'API kaydı reddetti'];
        }

        update_option(self::OPT_CLAIM_TOKEN, $claim_token, false);
        return ['ok' => true, 'claimUrl' => self::app_base() . '/claim/' . $claim_token];
    }

    /** AJAX polling handler'ı — ayarlar sayfasındaki script her birkaç
     * saniyede bir bunu çağırıyor. Kullanıcı `/claim/:token`de "Connect
     * this site"e basınca API'nin durumu `claimed`e döner; bu görüldüğü an
     * imzalı teyidi gönderip gerçek `connectionToken`ı alıyoruz — kullanıcı
     * ikinci bir adım atmıyor. */
    public static function ajax_poll_claim()
    {
        check_ajax_referer('wckonsol_poll_claim');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

        $claim_token = (string) get_option(self::OPT_CLAIM_TOKEN, '');
        if (!$claim_token) wp_send_json_success(['status' => 'none']);

        $status_response = wp_remote_get(self::api_base() . "/pairing/claim-tokens/{$claim_token}/status", ['timeout' => 10]);
        if (is_wp_error($status_response)) wp_send_json_success(['status' => 'pending']);
        $status = json_decode(wp_remote_retrieve_body($status_response), true)['status'] ?? 'pending';

        if ($status !== 'claimed') {
            wp_send_json_success(['status' => $status]);
            return;
        }

        $site_uuid = WCKonsol_Keys::site_uuid();
        $signature = WCKonsol_Keys::sign("{$claim_token}\n{$site_uuid}");
        $confirm = wp_remote_post(self::api_base() . "/pairing/claim-tokens/{$claim_token}/confirm", [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode(['claimToken' => $claim_token, 'signature' => $signature]),
            'timeout' => 15,
        ]);

        if (is_wp_error($confirm) || !self::is_success($confirm)) {
            wp_send_json_success(['status' => 'claimed']); // henüz teyit edemedik, bir sonraki pollde tekrar dener
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($confirm), true);
        update_option(self::OPT_CONNECTION_TOKEN, $data['connectionToken'], false);
        update_option(self::OPT_PAIRED_SITE_ID, $data['siteId'], true);
        delete_option(self::OPT_CLAIM_TOKEN);
        self::schedule_heartbeat();

        wp_send_json_success(['status' => 'connected']);
    }

    /** Yoast mı Rank Math mı aktif — pairing/heartbeat'in `capabilities`
     * bildirimi ve SEO REST ucu bunu kullanıyor. */
    public static function detect_seo_plugin(): ?string
    {
        if (defined('WPSEO_VERSION')) return 'yoast';
        if (class_exists('RankMath')) return 'rankmath';
        return null;
    }

    public static function send_heartbeat()
    {
        if (!self::is_paired()) return;

        $site_uuid = WCKonsol_Keys::site_uuid();
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = wp_generate_password(16, false);
        $signature = WCKonsol_Keys::sign("{$site_uuid}\n{$timestamp}\n{$nonce}");

        wp_remote_post(self::api_base() . '/pairing/heartbeat', [
            'headers' => ['content-type' => 'application/json'],
            'body' => wp_json_encode([
                'siteUuid' => $site_uuid,
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature' => $signature,
            ]),
            'timeout' => 10,
        ]);
    }
}

add_filter('cron_schedules', function ($schedules) {
    $schedules['wckonsol_five_minutes'] = ['interval' => 300, 'display' => __('Every 5 minutes', 'wckonsol-connector')];
    return $schedules;
});

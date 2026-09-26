<?php
if (!defined('ABSPATH')) exit;

class Konsol_Settings_Page
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_post_konsol_connect', [__CLASS__, 'handle_connect']);
        add_action('admin_post_konsol_disconnect', [__CLASS__, 'handle_disconnect']);
        add_action('admin_post_konsol_save_api_base', [__CLASS__, 'handle_save_api_base']);
        add_action('admin_post_konsol_start_claim', [__CLASS__, 'handle_start_claim']);
    }

    /** Önceden `add_options_page` ile Settings altında gizliydi — WooCommerce
     * entegrasyonu olan bir eklenti için görünürlüğü düşüktü. Artık kendi
     * üst düzey menüsü, WooCommerce'in kendi menüsünün (position 55.5)
     * hemen üstünde (55.4) — solda WooCommerce'in üstünde görünsün diye. */
    public static function add_menu()
    {
        add_menu_page(
            'Konsol',
            'Konsol',
            'manage_options',
            'konsol-connector',
            [__CLASS__, 'render'],
            'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMiIgaGVpZ2h0PSIzMiIgdmlld0JveD0iMCAwIDMyIDMyIiBmaWxsPSJub25lIiByb2xlPSJpbWciIGFyaWEtbGFiZWw9IldDIEtvbnNvbCI+CiAgPHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjgiIGhlaWdodD0iOCIgcng9IjIiIGZpbGw9IiNiOGM4ZDgiLz4KICA8cmVjdCB4PSIxMiIgeT0iMCIgd2lkdGg9IjgiIGhlaWdodD0iOCIgcng9IjIiIGZpbGw9IiNiOGM4ZDgiLz4KICA8cmVjdCB4PSIyNCIgeT0iMCIgd2lkdGg9IjgiIGhlaWdodD0iOCIgcng9IjIiIGZpbGw9IiNiOGM4ZDgiLz4KICA8cmVjdCB4PSIwIiB5PSIxMiIgd2lkdGg9IjgiIGhlaWdodD0iOCIgcng9IjIiIGZpbGw9IiNiOGM4ZDgiLz4KICA8cmVjdCB4PSIxMiIgeT0iMTIiIHdpZHRoPSI4IiBoZWlnaHQ9IjgiIHJ4PSIyIiBmaWxsPSIjN2MzYmVkIi8+CiAgPHJlY3QgeD0iMjQiIHk9IjEyIiB3aWR0aD0iOCIgaGVpZ2h0PSI4IiByeD0iMiIgZmlsbD0iI2I4YzhkOCIvPgogIDxyZWN0IHg9IjAiIHk9IjI0IiB3aWR0aD0iOCIgaGVpZ2h0PSI4IiByeD0iMiIgZmlsbD0iI2I4YzhkOCIvPgogIDxyZWN0IHg9IjEyIiB5PSIyNCIgd2lkdGg9IjgiIGhlaWdodD0iOCIgcng9IjIiIGZpbGw9IiNiOGM4ZDgiLz4KICA8cmVjdCB4PSIyNCIgeT0iMjQiIHdpZHRoPSI4IiBoZWlnaHQ9IjgiIHJ4PSIyIiBmaWxsPSIjYjhjOGQ4Ii8+Cjwvc3ZnPgo=',
            55.4
        );
    }

    public static function handle_connect()
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer('konsol_connect');

        $code = sanitize_text_field($_POST['pairing_code'] ?? '');
        $result = $code !== '' ? Konsol_Pairing::confirm($code) : ['ok' => false, 'error' => 'Kod boş olamaz'];

        $redirect = add_query_arg(
            $result['ok'] ? ['konsol_status' => 'connected'] : ['konsol_status' => 'error', 'konsol_error' => rawurlencode($result['error'])],
            admin_url('admin.php?page=konsol-connector'),
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_disconnect()
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer('konsol_disconnect');
        Konsol_Pairing::disconnect();
        wp_safe_redirect(add_query_arg(['konsol_status' => 'disconnected'], admin_url('admin.php?page=konsol-connector')));
        exit;
    }

    public static function handle_save_api_base()
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer('konsol_save_api_base');
        update_option(Konsol_Pairing::OPT_API_BASE, esc_url_raw($_POST['api_base'] ?? ''), true);
        update_option(Konsol_Pairing::OPT_APP_BASE, esc_url_raw($_POST['app_base'] ?? ''), true);
        wp_safe_redirect(add_query_arg(['konsol_status' => 'api_base_saved'], admin_url('admin.php?page=konsol-connector')));
        exit;
    }

    /** "Get started without an account yet" — §3.1 akışını başlatır, sonucu
     * (`claimUrl`) bir sonraki sayfa yüklemesinde göstermek için geçici bir
     * transient'e koyuyor (query string'e koymak URL'i kirletir/loglanır). */
    public static function handle_start_claim()
    {
        if (!current_user_can('manage_options')) wp_die('forbidden', 403);
        check_admin_referer('konsol_start_claim');

        $result = Konsol_Pairing::start_claim();
        if ($result['ok']) {
            set_transient('konsol_claim_url_' . get_current_user_id(), $result['claimUrl'], 60);
        }

        $redirect = add_query_arg(
            $result['ok'] ? ['konsol_status' => 'claim_started'] : ['konsol_status' => 'error', 'konsol_error' => rawurlencode($result['error'])],
            admin_url('admin.php?page=konsol-connector'),
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public static function render()
    {
        $paired = Konsol_Pairing::is_paired();
        $status = sanitize_text_field($_GET['konsol_status'] ?? '');
        $error = sanitize_text_field($_GET['konsol_error'] ?? '');
        $claim_url = $status === 'claim_started' ? get_transient('konsol_claim_url_' . get_current_user_id()) : false;
        ?>
        <div class="wrap">
            <h1>Konsol Connector</h1>

            <?php if ($status === 'connected'): ?>
                <div class="notice notice-success"><p>Bağlandı — Konsol artık bu siteye (WC Konsol ürünleri veya WP Konsol yazıları) SEO ve medya yeteneklerini kullanarak yazabilir.</p></div>
            <?php elseif ($status === 'disconnected'): ?>
                <div class="notice notice-warning"><p>Bağlantı kesildi.</p></div>
            <?php elseif ($status === 'error'): ?>
                <div class="notice notice-error"><p>Eşleştirme başarısız: <?php echo esc_html($error); ?></p></div>
            <?php elseif ($status === 'api_base_saved'): ?>
                <div class="notice notice-success"><p>Adresler kaydedildi.</p></div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Site UUID</th>
                    <td><code><?php echo esc_html(Konsol_Keys::site_uuid()); ?></code></td>
                </tr>
                <tr>
                    <th scope="row">Durum</th>
                    <td id="konsol-status-cell"><?php echo $paired ? '<strong style="color:#1a7f37">Bağlı</strong>' : '<strong style="color:#996800">Bağlı değil</strong>'; ?></td>
                </tr>
            </table>

            <?php if ($paired): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="konsol_disconnect">
                    <?php wp_nonce_field('konsol_disconnect'); ?>
                    <?php submit_button('Bağlantıyı kes', 'delete'); ?>
                </form>
            <?php else: ?>
                <h2>Eşleştirme kodu</h2>
                <p>WC Konsol'da <strong>Stores → Connect with the plugin</strong>, veya WP Konsol'da <strong>Sites → Connect with the plugin</strong> ekranından bir kod üretin, buraya yapıştırın (15 dakika geçerli).</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="konsol_connect">
                    <?php wp_nonce_field('konsol_connect'); ?>
                    <input type="text" name="pairing_code" class="regular-text" placeholder="Pairing code" required>
                    <?php submit_button('Bağlan', 'primary', 'submit', false); ?>
                </form>

                <h2>Henüz hesabınız yok mu?</h2>
                <p>WC Konsol'a hiç gitmeden bu siteyi hazırlayın — bağlantıyı bitirmek için açılan sayfada kaydolun/giriş yapın.</p>
                <?php if ($claim_url): ?>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url($claim_url); ?>" target="_blank" rel="noopener">
                            WC Konsol'da devam et →
                        </a>
                    </p>
                    <p id="konsol-claim-waiting" style="color:#996800;">Bağlantının tamamlanması bekleniyor… bu sayfayı açık bırakın.</p>
                    <script>
                    (function () {
                        var attempts = 0;
                        var poll = function () {
                            attempts++;
                            if (attempts > 60) { // ~3 dakika
                                document.getElementById('konsol-claim-waiting').textContent = 'Zaman aşımı — sayfayı yenileyip tekrar deneyin.';
                                return;
                            }
                            var body = new URLSearchParams();
                            body.set('action', '<?php echo esc_js(Konsol_Pairing::AJAX_ACTION); ?>');
                            body.set('_ajax_nonce', '<?php echo esc_js(wp_create_nonce(Konsol_Pairing::AJAX_ACTION)); ?>');
                            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                                .then(function (r) { return r.json(); })
                                .then(function (json) {
                                    var s = json && json.data && json.data.status;
                                    if (s === 'connected') {
                                        window.location.reload();
                                    } else {
                                        setTimeout(poll, 3000);
                                    }
                                })
                                .catch(function () { setTimeout(poll, 3000); });
                        };
                        setTimeout(poll, 3000);
                    })();
                    </script>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="konsol_start_claim">
                        <?php wp_nonce_field('konsol_start_claim'); ?>
                        <?php submit_button('Get started without an account yet', 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Gelişmiş</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="konsol_save_api_base">
                <?php wp_nonce_field('konsol_save_api_base'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="api_base">API base URL</label></th>
                        <td>
                            <input type="text" id="api_base" name="api_base" class="regular-text"
                                value="<?php echo esc_attr(get_option(Konsol_Pairing::OPT_API_BASE, '')); ?>"
                                placeholder="<?php echo esc_attr(KONSOL_API_BASE); ?>">
                            <p class="description">Yalnızca yerel geliştirme/staging için — boş bırakılırsa varsayılan (üretim) adres kullanılır.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="app_base">App base URL</label></th>
                        <td>
                            <input type="text" id="app_base" name="app_base" class="regular-text"
                                value="<?php echo esc_attr(get_option(Konsol_Pairing::OPT_APP_BASE, '')); ?>"
                                placeholder="<?php echo esc_attr(class_exists('WooCommerce') ? KONSOL_APP_BASE_WCKONSOL : KONSOL_APP_BASE_WPKONSOL); ?>">
                            <p class="description">"Get started without an account yet" bağlantısının açtığı adres — boş bırakılırsa WooCommerce kurulu olup olmamasına göre otomatik seçilir (<?php echo esc_html(KONSOL_APP_BASE_WCKONSOL); ?> / <?php echo esc_html(KONSOL_APP_BASE_WPKONSOL); ?>).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Kaydet'); ?>
            </form>
        </div>
        <?php
    }
}

<?php
/**
 * Plugin Name:       Transer Translate
 * Plugin URI:        https://transer.io
 * Description:       ページ全体をサーバーサイドで翻訳し、多言語SEOタグ・言語ボックス付きで
 *                     返すtranser.io連携プラグイン（Module/Plugin製品のWordPress版）。
 *                     Pythonクライアントライブラリ「transer」と同じ translate.service API
 *                     (/translate-page) を利用する。
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            transer.io
 * License:           GPLv2 or later
 * Text Domain:       transer-translate
 *
 * ── 仕組み ──
 * 1. 訪問者のCookie「transer_lang」（言語ボックスが言語切り替え時にセットする想定）を見る。
 * 2. 原文言語(既定: ja)であれば何もしない（既存ページをそのまま表示）。
 * 3. それ以外の言語なら、WordPressが生成したHTML全体を出力バッファで受け取り、
 *    translate.service の /translate-page へ hostname・pathname・契約言語一覧
 *    (contract_langs) とともに送信し、返ってきた翻訳済みHTML（canonical/hreflang
 *    タグ・言語ボックス用スクリプトが自動挿入済み）に差し替えて出力する。
 * 4. 通信に失敗した場合は原文をそのまま表示する（翻訳できないより表示優先）。
 *
 * ページ単位のキャッシュ・差分翻訳・多言語SEOタグの生成はすべてサーバー側
 * (translate.service)の責任であり、このプラグインは「HTMLを渡して受け取るだけ」の
 * 薄いブリッジに徹する（Pythonクライアントライブラリ「transer」と設計思想は同じ）。
 */

if (!defined('ABSPATH')) {
    exit; // 直接アクセス禁止
}

define('TRANSER_TRANSLATE_VERSION', '0.1.0');
define('TRANSER_TRANSLATE_OPTION_KEY', 'transer_translate_settings');
define('TRANSER_TRANSLATE_COOKIE_NAME', 'transer_lang');

/**
 * 設定値を取得する（既定値付き）。
 */
function transer_translate_get_option($key, $default = '') {
    $settings = get_option(TRANSER_TRANSLATE_OPTION_KEY, array());
    if (!is_array($settings) || !isset($settings[$key]) || $settings[$key] === '') {
        return $default;
    }
    return $settings[$key];
}

/* ============================================================
 * 管理画面: 設定ページ（設定 > Transer Translate）
 * ============================================================ */

add_action('admin_menu', function () {
    add_options_page(
        'Transer Translate 設定',
        'Transer Translate',
        'manage_options',
        'transer-translate',
        'transer_translate_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('transer_translate_group', TRANSER_TRANSLATE_OPTION_KEY, array(
        'sanitize_callback' => 'transer_translate_sanitize_settings',
    ));
});

/**
 * 保存前のサニタイズ。contract_langs はカンマ区切り文字列で入力してもらい、
 * 内部的には配列として保持する。
 */
function transer_translate_sanitize_settings($input) {
    $output = array();
    $output['api_key'] = isset($input['api_key']) ? sanitize_text_field(trim($input['api_key'])) : '';
    $output['base_url'] = isset($input['base_url']) ? esc_url_raw(rtrim(trim($input['base_url']), '/')) : 'https://api.transer.io';
    $output['hostname'] = isset($input['hostname']) ? sanitize_text_field(trim($input['hostname'])) : '';
    $output['source_lang'] = isset($input['source_lang']) ? sanitize_text_field(trim($input['source_lang'])) : 'ja';

    $langs_raw = isset($input['contract_langs']) ? (string) $input['contract_langs'] : '';
    $langs = array_filter(array_map('trim', explode(',', $langs_raw)));
    $output['contract_langs'] = array_values(array_unique(array_map('sanitize_text_field', $langs)));

    return $output;
}

function transer_translate_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $api_key = transer_translate_get_option('api_key', '');
    $base_url = transer_translate_get_option('base_url', 'https://api.transer.io');
    $hostname = transer_translate_get_option('hostname', '');
    $source_lang = transer_translate_get_option('source_lang', 'ja');
    $contract_langs = transer_translate_get_option('contract_langs', array());
    $default_hostname = wp_parse_url(home_url(), PHP_URL_HOST);
    ?>
    <div class="wrap">
        <h1>Transer Translate 設定</h1>
        <p>サーバーサイドでページを翻訳し、多言語SEOタグ・言語ボックス付きで返す
           transer.io連携の設定です。実際の翻訳・キャッシュ・差分判定・SEOタグ生成は
           すべてtranslate.service側で行われます。</p>
        <form method="post" action="options.php">
            <?php settings_fields('transer_translate_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="transer_api_key">APIキー</label></th>
                    <td>
                        <input type="text" id="transer_api_key" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[api_key]"
                               value="<?php echo esc_attr($api_key); ?>" autocomplete="off">
                        <p class="description">transer.io管理画面で発行したAPIキー。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_hostname">サイトのホスト名</label></th>
                    <td>
                        <input type="text" id="transer_hostname" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[hostname]"
                               value="<?php echo esc_attr($hostname); ?>"
                               placeholder="<?php echo esc_attr($default_hostname); ?>">
                        <p class="description">
                            空欄の場合はサイトURLから自動判定します（現在: <code><?php echo esc_html($default_hostname); ?></code>）。
                            ページキャッシュ・多言語SEOタグのURL生成に使われます。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_source_lang">原文言語</label></th>
                    <td>
                        <input type="text" id="transer_source_lang" class="small-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[source_lang]"
                               value="<?php echo esc_attr($source_lang); ?>">
                        <p class="description">通常は <code>ja</code>。この言語がリクエストされた場合は翻訳せず原文を表示します。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_contract_langs">契約言語一覧</label></th>
                    <td>
                        <input type="text" id="transer_contract_langs" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[contract_langs]"
                               value="<?php echo esc_attr(implode(',', $contract_langs)); ?>"
                               placeholder="en,zh-TW,ko">
                        <p class="description">
                            契約している翻訳先言語コードをカンマ区切りで指定してください。
                            多言語SEOタグ(hreflang)の本数に使われます。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_base_url">translate.service のURL</label></th>
                    <td>
                        <input type="text" id="transer_base_url" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[base_url]"
                               value="<?php echo esc_attr($base_url); ?>">
                        <p class="description">通常は変更不要です（既定: <code>https://api.transer.io</code>）。</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/* ============================================================
 * フロント側: ページ翻訳の本体
 * ============================================================ */

/**
 * リクエストされた翻訳先言語をCookieから取得する。
 * 言語ボックス（translate.serviceが自動挿入するtrsv2.js）が
 * 言語切り替え時にこのCookieをセットする想定。
 */
function transer_translate_get_requested_lang() {
    if (empty($_COOKIE[TRANSER_TRANSLATE_COOKIE_NAME])) {
        return null;
    }
    $lang = sanitize_text_field(wp_unslash($_COOKIE[TRANSER_TRANSLATE_COOKIE_NAME]));
    // 言語コードとして妥当な文字だけ許可（英字・数字・ハイフンのみ）。不正値は無視する。
    if (!preg_match('/^[A-Za-z0-9-]{2,10}$/', $lang)) {
        return null;
    }
    return $lang;
}

/**
 * このリクエストで翻訳処理を行うべきか判定する。
 * 管理画面・REST API・AJAX・cron・非GETリクエスト・設定不備の場合は行わない。
 */
function transer_translate_should_process() {
    if (is_admin()) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }
    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return false;
    }
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }

    $lang = transer_translate_get_requested_lang();
    $source_lang = transer_translate_get_option('source_lang', 'ja');
    if (!$lang || $lang === $source_lang) {
        return false; // 原文言語 or 言語指定なし → 何もしない
    }

    $api_key = transer_translate_get_option('api_key', '');
    if (empty($api_key)) {
        return false; // 設定不備。原文のまま表示する
    }

    return true;
}

/**
 * template_redirect の早い段階で出力バッファリングを開始する。
 * 実際の翻訳呼び出しは transer_translate_buffer() コールバックの中で行う。
 */
add_action('template_redirect', 'transer_translate_maybe_start_buffer', 0);

function transer_translate_maybe_start_buffer() {
    if (!transer_translate_should_process()) {
        return;
    }
    ob_start('transer_translate_buffer_callback');
}

/**
 * 出力バッファのコールバック。WordPressが生成したHTML全体を受け取り、
 * translate.service の /translate-page に送信して翻訳済みHTMLに差し替える。
 * 通信に失敗した場合は元のHTMLをそのまま返す（表示優先のフォールバック）。
 */
function transer_translate_buffer_callback($html) {
    // HTMLらしき応答でなければ触らない（JSON/XML等を誤って壊さないための保険）
    if (trim($html) === '' || stripos(ltrim($html), '<') !== 0) {
        return $html;
    }

    $api_key = transer_translate_get_option('api_key', '');
    $base_url = transer_translate_get_option('base_url', 'https://api.transer.io');
    $source_lang = transer_translate_get_option('source_lang', 'ja');
    $target_lang = transer_translate_get_requested_lang();
    $contract_langs = transer_translate_get_option('contract_langs', array());

    $hostname = transer_translate_get_option('hostname', '');
    if (empty($hostname)) {
        $hostname = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    }

    $pathname = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '/';
    if (empty($pathname)) {
        $pathname = '/';
    }

    $body = wp_json_encode(array(
        'html'            => $html,
        'source'          => $source_lang,
        'target'          => $target_lang,
        'hostname'        => $hostname,
        'pathname'        => $pathname,
        'contract_langs'  => $contract_langs,
    ));

    if ($body === false) {
        return $html; // JSONエンコード失敗。原文のまま表示。
    }

    $response = wp_remote_post(
        rtrim($base_url, '/') . '/translate-page',
        array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => $body,
        )
    );

    if (is_wp_error($response)) {
        transer_translate_log_error('translate.service への接続に失敗: ' . $response->get_error_message());
        return $html; // フォールバック: 通信失敗時は原文を表示
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        transer_translate_log_error('translate.service が異常応答: HTTP ' . $status . ' body=' . wp_remote_retrieve_body($response));
        return $html;
    }

    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($decoded) || empty($decoded['html'])) {
        transer_translate_log_error('translate.service の応答形式が不正です');
        return $html;
    }

    return $decoded['html'];
}

/**
 * デバッグ用ログ出力。WP_DEBUG_LOG が有効な場合のみ記録する
 * （本番運用で不要なログが溜まり続けないようにするため）。
 */
function transer_translate_log_error($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[transer-translate] ' . $message);
    }
}

/* ============================================================
 * 有効化・無効化時の後始末
 * ============================================================ */

register_activation_hook(__FILE__, function () {
    // 現時点では追加のテーブル作成・マイグレーションは無し。将来の拡張用フック。
});

register_deactivation_hook(__FILE__, function () {
    // オプション自体は残す（再有効化時に設定を保持するため、削除しない）。
});

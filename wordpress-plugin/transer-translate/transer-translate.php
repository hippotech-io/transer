<?php
/**
 * Plugin Name:       Transer Translate
 * Plugin URI:        https://transer.io
 * Description:       ページ全体をサーバーサイドで翻訳し、多言語SEOタグ・言語ボックス付きで
 *                     返すtranser.io連携プラグイン（Module/Plugin製品のWordPress版）。
 *                     Pythonクライアントライブラリ「transer」と同じ translate.service API
 *                     (/translate-page) を利用する。
 * Version:           0.7.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            transer.io
 * License:           GPLv2 or later
 * Text Domain:       transer-translate
 *
 * ── 仕組み ──
 * 1. URLの先頭セグメントが契約言語と一致するか（例: /en/about）を、initフックの
 *    早い段階で $_SERVER['REQUEST_URI'] 自体から確認する。一致すればそれを
 *    最優先で採用し、プレフィックスを除去したURLでWordPress本体の通常の
 *    URL解決（parse_request）に処理を委ねる。サーバー側（Apache/nginx）の
 *    Rewrite設定は一切不要。検索エンジンのクローラーのようにCookieを送らない
 *    アクセスでも、URL通りの言語で正しく配信するための対応。
 * 2. URLにプレフィックスが無ければ、訪問者のCookie「transer_lang」
 *    （言語ボックスが言語切り替え時にセットする想定）を見る。
 * 3. 原文言語(既定: ja)であれば何もしない（既存ページをそのまま表示）。
 * 4. それ以外の言語なら、WordPressが生成したHTML全体を出力バッファで受け取り、
 *    translate.service の /translate-page へ hostname・pathname・契約言語一覧
 *    (contract_langs) とともに送信し、返ってきた翻訳済みHTML（canonical/hreflang
 *    タグ・言語ボックス用スクリプトが自動挿入済み）に差し替えて出力する。
 * 5. 通信に失敗した場合は原文をそのまま表示する（翻訳できないより表示優先）。
 *
 * ページ単位の翻訳・多言語SEOタグの生成はすべてサーバー側
 * (translate.service)の責任であり、このプラグインは「HTMLを渡して受け取るだけ」の
 * 薄いブリッジに徹する（Pythonクライアントライブラリ「transer」と設計思想は同じ）。
 */

if (!defined('ABSPATH')) {
    exit; // 直接アクセス禁止
}

define('TRANSER_TRANSLATE_VERSION', '0.7.4');
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
        __('Transer Translate 設定', 'transer-translate'),
        __('Transer Translate', 'transer-translate'),
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

    // 【重要】register_setting()のsanitize_callbackは、WordPressの仕様上・環境により
    // 同一保存で2回連続して呼ばれることがある。1回目で既に配列化された$output['contract_langs']が
    // 何らかの経路で2回目の$inputとして渡された場合、is_array()チェックが無いと
    // (string)キャストで配列が文字列"Array"に化けてしまい（PHPの既知の挙動）、
    // 実際には"Array"という無意味な1件だけの契約言語として保存されてしまう
    // （実機検証で発覚した不具合）。文字列・配列どちらで来ても正しく処理できるようにする。
    $langs_input = isset($input['contract_langs']) ? $input['contract_langs'] : '';
    $langs_raw   = is_array($langs_input) ? $langs_input : explode(',', (string) $langs_input);
    $langs = array_filter(array_map('trim', $langs_raw));
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
        <h1><?php esc_html_e('Transer Translate 設定', 'transer-translate'); ?></h1>
        <p><?php esc_html_e('サーバーサイドでページを翻訳し、多言語SEOタグ・言語ボックス付きで返す transer.io連携の設定です。実際の翻訳・SEOタグ生成はすべてtranslate.service側で行われます。', 'transer-translate'); ?></p>
        <form method="post" action="options.php">
            <?php settings_fields('transer_translate_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="transer_api_key"><?php esc_html_e('APIキー', 'transer-translate'); ?></label></th>
                    <td>
                        <input type="text" id="transer_api_key" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[api_key]"
                               value="<?php echo esc_attr($api_key); ?>" autocomplete="off">
                        <p class="description"><?php esc_html_e('transer.io管理画面で発行したAPIキー。', 'transer-translate'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_hostname"><?php esc_html_e('サイトのホスト名', 'transer-translate'); ?></label></th>
                    <td>
                        <input type="text" id="transer_hostname" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[hostname]"
                               value="<?php echo esc_attr($hostname); ?>"
                               placeholder="<?php echo esc_attr($default_hostname); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: サイトのホスト名（例: example.com） */
                                esc_html__('空欄の場合はサイトURLから自動判定します（現在: %s）。多言語SEOタグのURL生成に使われます。', 'transer-translate'),
                                '<code>' . esc_html($default_hostname) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_source_lang"><?php esc_html_e('原文言語', 'transer-translate'); ?></label></th>
                    <td>
                        <input type="text" id="transer_source_lang" class="small-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[source_lang]"
                               value="<?php echo esc_attr($source_lang); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: 言語コード "ja" */
                                esc_html__('通常は %s。この言語がリクエストされた場合は翻訳せず原文を表示します。', 'transer-translate'),
                                '<code>ja</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_contract_langs"><?php esc_html_e('契約言語一覧', 'transer-translate'); ?></label></th>
                    <td>
                        <input type="text" id="transer_contract_langs" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[contract_langs]"
                               value="<?php echo esc_attr(implode(',', $contract_langs)); ?>"
                               placeholder="en,zh-TW,ko">
                        <p class="description">
                            <?php esc_html_e('契約している翻訳先言語コードをカンマ区切りで指定してください。多言語SEOタグ(hreflang)の本数に使われます。', 'transer-translate'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="transer_base_url"><?php esc_html_e('translate.service のURL', 'transer-translate'); ?></label></th>
                    <td>
                        <input type="text" id="transer_base_url" class="regular-text"
                               name="<?php echo esc_attr(TRANSER_TRANSLATE_OPTION_KEY); ?>[base_url]"
                               value="<?php echo esc_attr($base_url); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: 既定のURL */
                                esc_html__('通常は変更不要です（既定: %s）。', 'transer-translate'),
                                '<code>https://api.transer.io</code>'
                            );
                            ?>
                        </p>
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
 * URLの言語プレフィックス（例: /en/about）を、WordPressが通常のURL解決を
 * 行う前に検出・除去しておくためのグローバル変数。
 * transer_translate_strip_lang_prefix()（initフックの早い段階）でセットされる。
 */
$GLOBALS['transer_translate_url_lang']     = null;
$GLOBALS['transer_translate_url_pathname'] = null;

/**
 * WordPress本体がリクエストURLを解決する（parse_request()）より前に、
 * $_SERVER['REQUEST_URI'] 自体からプレフィックスを検出・除去しておく。
 *
 * 【重要な設計上の注意】以前のバージョンは do_parse_request フィルタ内で
 * $wp->request を読もうとしていたが、このフィルタはWordPressが
 * $wp->request をまだ計算する前（$_SERVER['REQUEST_URI']から読み取るより前）
 * に発火するため、常に空の値しか読めず、プレフィックス検出が一切機能しない
 * バグがあった。$_SERVER['REQUEST_URI'] 自体を早い段階（initフック）で
 * 書き換えておけば、WordPress本体のparse_request()が書き換え後の値を
 * そのまま読み取って通常通り処理してくれるため、この問題を回避できる。
 *
 * 【なぜ必要か】このフックが無いと、/en/about のようなURLは「Cookie」でしか
 * 言語判定されないため、Cookieを送らない検索エンジンのクローラーには常に
 * 原文（日本語）が返ってしまい、canonical/hreflangタグの内容と実際の配信内容が
 * 食い違ってしまう（SEO上意味を持たない状態になる）。ここでURLを最優先で
 * 見ることで、クローラーが直接 /en/about にアクセスしてきた場合でも
 * 正しい言語で配信できるようにする。
 *
 * 除去後のパスはWordPress自身の通常の投稿・固定ページ解決ロジックに
 * そのまま渡されるため、パーマリンク構造を問わず動作する
 * （個々の投稿タイプ・カテゴリ構造に合わせた特別な書き換えルールは不要で、
 *  サーバー側（Apache/nginx）のRewrite設定も一切不要）。
 */
add_action('init', 'transer_translate_strip_lang_prefix', 1);
function transer_translate_strip_lang_prefix() {
    $contract_langs = transer_translate_get_option('contract_langs', array());
    if (empty($contract_langs) || empty($_SERVER['REQUEST_URI'])) {
        return;
    }

    $raw_uri = wp_unslash($_SERVER['REQUEST_URI']);
    $path    = (string) wp_parse_url($raw_uri, PHP_URL_PATH);
    $trimmed = trim($path, '/');

    foreach ($contract_langs as $lang) {
        $lang = trim($lang);
        if ($lang === '') {
            continue;
        }

        if ($trimmed === $lang) {
            // 例: /en または /en/ → 言語のトップページ
            $GLOBALS['transer_translate_url_lang']     = $lang;
            $GLOBALS['transer_translate_url_pathname']  = '/';
            $_SERVER['REQUEST_URI'] = preg_replace(
                '#^/' . preg_quote($lang, '#') . '/?#',
                '/',
                $raw_uri,
                1
            );
            return;
        }

        if (strpos($trimmed, $lang . '/') === 0) {
            // 例: /en/about → REQUEST_URIから "en/" を除去し、WordPress本体には
            // /about として解決させる
            $rest = substr($trimmed, strlen($lang) + 1);
            $GLOBALS['transer_translate_url_lang']     = $lang;
            $GLOBALS['transer_translate_url_pathname']  = '/' . $rest;
            $_SERVER['REQUEST_URI'] = preg_replace(
                '#^/' . preg_quote($lang, '#') . '/#',
                '/',
                $raw_uri,
                1
            );
            return;
        }
    }
}

/**
 * リクエストされた翻訳先言語をCookieから取得する。
 * 言語ボックス（translate.serviceが自動挿入するlangbox.js）が
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
 * このリクエストで要求されている言語と、翻訳API送信用の実質的なパス名を判定する。
 *
 * 優先順位:
 *   1. URLの言語プレフィックス（transer_translate_strip_lang_prefix()が検出したもの）。
 *      サーバー側のRewrite設定は一切不要（do_parse_requestフックで完結するため）。
 *   2. 上記に該当しなければ、従来通りCookie（transer_lang、言語ボックスがセットする）を見る。
 *
 * @return array{lang: string|null, pathname: string}
 */
function transer_translate_resolve_request() {
    if (!empty($GLOBALS['transer_translate_url_lang'])) {
        return array(
            'lang'     => $GLOBALS['transer_translate_url_lang'],
            'pathname' => $GLOBALS['transer_translate_url_pathname'],
        );
    }

    $default_pathname = isset($_SERVER['REQUEST_URI'])
        ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
        : '/';
    if (empty($default_pathname)) {
        $default_pathname = '/';
    }

    return array('lang' => transer_translate_get_requested_lang(), 'pathname' => $default_pathname);
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

    $resolved = transer_translate_resolve_request();
    $lang = $resolved['lang'];
    $source_lang = transer_translate_get_option('source_lang', 'ja');
    $contract_langs = transer_translate_get_option('contract_langs', array());

    // 【重要】Cookieの値は訪問者のブラウザから送られてくるものなので、開発者ツール等で
    // どんな値にでも書き換えられる（"fr"のような契約外言語も指定できてしまう）。
    // 「原文言語と違う値であれば処理する」という判定だと、契約外の値が来た時に
    // translate.service側で翻訳されずそのまま返ってくるにも関わらず、
    // maybe_inject_langbox() 側は「翻訳されるはず」と判断して言語ボックスの
    // 注入をスキップしてしまい、「日本語ページなのに言語ボックスが消える」
    // 不具合が起きる。必ず契約言語一覧に実在する値かどうかで判定すること。
    if (!$lang || $lang === $source_lang || !in_array($lang, $contract_langs, true)) {
        return false; // 原文言語・未指定・契約外のいずれか → 何もしない
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
    $resolved = transer_translate_resolve_request();
    $target_lang = $resolved['lang'];
    $pathname = $resolved['pathname'];
    $contract_langs = transer_translate_get_option('contract_langs', array());

    $hostname = transer_translate_get_option('hostname', '');
    if (empty($hostname)) {
        $hostname = (string) wp_parse_url(home_url(), PHP_URL_HOST);
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
    // v0.6.0より前のバージョンが .htaccess に書き込んでいた可能性のある
    // 仮想URLルールを、念のため削除しておく（v0.6.0以降はdo_parse_requestフックで
    // 完結するため、.htaccessへの書き込みは一切行わない）。
    transer_translate_cleanup_legacy_htaccess_rules();
});

/**
 * 【重要】v0.7.1以前のバグ（sanitize_settingsが二重発火した場合に
 * contract_langsが文字列"Array"1件に化ける不具合）で、既に壊れた値が
 * 保存されてしまっている可能性がある。
 *
 * register_activation_hook は「プラグインを普通に更新しただけ」では発火しない
 * （無効化→再有効化した場合のみ発火するWordPressの仕様）ため、実際の更新作業では
 * ほぼ効果が無い。admin_init（管理画面アクセスのたびに軽量なチェックだけ行う）で
 * 自己修復することで、更新後に管理者が一度でも管理画面を開けば自動的に直るようにする。
 */
add_action('admin_init', 'transer_translate_self_heal_contract_langs');
function transer_translate_self_heal_contract_langs() {
    $settings = get_option(TRANSER_TRANSLATE_OPTION_KEY, array());
    if (!isset($settings['contract_langs']) || !is_array($settings['contract_langs'])) {
        return;
    }
    $cleaned = array_values(array_filter($settings['contract_langs'], function ($lang) {
        return $lang !== 'Array' && $lang !== '';
    }));
    if ($cleaned !== $settings['contract_langs']) {
        $settings['contract_langs'] = $cleaned;
        update_option(TRANSER_TRANSLATE_OPTION_KEY, $settings);
    }
}

register_deactivation_hook(__FILE__, function () {
    // オプション自体は残す（再有効化時に設定を保持するため、削除しない）。
    // 設定を完全に削除したい場合は「無効化」ではなく「削除（アンインストール）」を行うこと
    // （下記 transer_translate_uninstall() が呼ばれ、その時点で削除される）。
});

/**
 * v0.6.0より前のバージョンが .htaccess に自動追記していた仮想URLルールを削除する。
 * v0.6.0以降はdo_parse_requestフックで言語判定・URL解決が完結するため、
 * サーバー側のRewrite設定（.htaccessへの書き込み含む）は一切不要になった。
 * 古いルールが残ったままだと、do_parse_requestフックとの二重処理で
 * 意図しない挙動になり得るため、有効化のたびに念のため削除しておく。
 */
function transer_translate_cleanup_legacy_htaccess_rules() {
    if (!function_exists('insert_with_markers')) {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
    }

    $htaccess_path = trailingslashit(get_home_path()) . '.htaccess';

    // .htaccess自体が存在しない（nginx環境等）場合は何もしない。
    if (!file_exists($htaccess_path)) {
        return false;
    }

    // このプラグインが過去に書き込んだブロックだけを空にする
    // （他プラグイン・WordPress本体のルールには一切触れない）。
    return insert_with_markers($htaccess_path, 'TranserTranslate', array());
}

/**
 * プラグインを完全に削除（アンインストール）した時に、保存済み設定
 * （APIキー・契約言語など）も一緒に削除する。
 *
 * 【重要】register_uninstall_hook() には、register_activation_hook() /
 * register_deactivation_hook() と違って無名関数（クロージャ）を渡せない
 * WordPressの仕様上の制約があるため、名前付き関数として定義する。
 */
register_uninstall_hook(__FILE__, 'transer_translate_uninstall');

function transer_translate_uninstall() {
    // WordPress本体からの正規のアンインストール呼び出しであることを確認する
    // （直接アクセスや不正な呼び出しを防ぐ、WordPress公式の推奨チェック）。
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        return;
    }
    delete_option(TRANSER_TRANSLATE_OPTION_KEY);
    transer_translate_cleanup_legacy_htaccess_rules(); // 念のため、残っている可能性のある旧ルールも削除
}

/* ============================================================
 * フロント側: 原文ページへの言語ボックス自動注入
 * ============================================================
 * 翻訳済みページには translate.service が言語ボックス用スクリプト
 * （langbox.js）を自動で埋め込むが、原文（日本語）ページは
 * translate.service を一切経由しないため、このままでは言語ボックス
 * 自体が存在せず、訪問者が他言語へ切り替える手段が無い。
 * そこで原文ページ表示時だけ、常設の軽量スクリプトを wp_head で
 * 埋め込み、言語ボックスを描画する（Pythonクライアント利用時は
 * お客様がテンプレートに1行追加する必要があるが、WordPressの場合は
 * プラグインが自動で行うため、追加の手間は一切発生しない）。
 */
add_action('wp_head', 'transer_translate_maybe_inject_langbox');

function transer_translate_maybe_inject_langbox() {
    if (is_admin()) {
        return;
    }
    // 翻訳処理を行うページ（＝レスポンスの中にtranslate.serviceが
    // 自前の言語ボックスを既に埋め込む）では、二重に表示されないよう注入しない。
    if (transer_translate_should_process()) {
        return;
    }

    $api_key = transer_translate_get_option('api_key', '');
    if (empty($api_key)) {
        return; // APIキー未設定ならボックスも出さない
    }

    $base_url = transer_translate_get_option('base_url', 'https://api.transer.io');
    $source_lang = transer_translate_get_option('source_lang', 'ja');
    $contract_langs = transer_translate_get_option('contract_langs', array());

    if (empty($contract_langs)) {
        return; // 翻訳先言語が1つも設定されていなければ、ボックスを出しても意味が無い
    }

    $langbox_url = rtrim($base_url, '/') . '/js/langbox.js';

    // 契約している翻訳先言語一覧は、langbox.js側がリクエスト元ドメインから
    // 自動判定するため、クエリパラメータでは原文言語（source_lang）のみ渡す。
    printf(
        '<script src="%s" charset="utf-8"></script>' . "\n",
        esc_url(add_query_arg('lang', $source_lang, $langbox_url))
    );
}

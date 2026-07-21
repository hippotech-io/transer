=== Transer Translate ===
Contributors: transerio
Tags: translation, multilingual, seo, hreflang
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ページ全体をサーバーサイドで翻訳し、多言語SEOタグ・言語ボックス付きで返すtranser.io連携プラグイン。

== Description ==

Transer Translate は、WordPressサイトのページ全体を transer.io の翻訳サーバー
（translate.service）へ送信し、翻訳済みHTML（canonical/hreflangタグ・言語ボックス
用スクリプト付き）を受け取って差し替えるプラグインです。

* `hreflang`/`canonical`タグは契約言語数に応じてサーバー側が自動生成します。
* 通信に失敗した場合は原文をそのまま表示します（翻訳不可より表示優先）。

== Installation ==

このプラグインの導入方法は、大きく分けて2通りあります。多くの場合は方法A（WordPress管理画面から完結）で十分です。

= 方法A: WordPress管理画面からインストールする（推奨） =

1. transer.io の管理画面（「APIキー管理」ページ）から、プラグインのzipファイルをダウンロードします。
2. WordPress管理画面にログインし、「プラグイン」→「新規追加」を開きます。
3. 画面上部の「プラグインのアップロード」ボタンをクリックします。
4. 「ファイルを選択」から、手順1でダウンロードしたzipファイルを選び、「今すぐインストール」をクリックします。
5. インストール完了後に表示される「プラグインを有効化」ボタンをクリックします。

= 方法B: FTP/SSHでサーバーへ手動配置する =

サーバーへの直接アクセス手段をお持ちの方向けの方法です。

1. このプラグインのフォルダ一式を、そのまま `wp-content/plugins/transer-translate/` に配置します
   （`transer-translate.php` と `readme.txt` が直下に来るようにしてください）。
2. WordPress管理画面の「プラグイン」一覧から「Transer Translate」を有効化します。

= 有効化後の設定 =

方法A・Bのどちらでインストールした場合も、有効化後の設定手順は共通です。

1. WordPress管理画面の左メニューから「設定」→「Transer Translate」を開きます。
2. 以下の項目を入力し、「変更を保存」をクリックします。

* **APIキー** — transer.io管理画面（「APIキー管理」ページ）で発行したAPIキーを入力します（必須）。
* **サイトのホスト名** — 空欄のままで構いません（自動的にサイトURLから判定されます）。transer.io側の「ドメイン管理」で登録したホスト名と、大文字・小文字や`www`の有無まで完全に一致している必要があります。
* **原文言語** — 通常は `ja` のままで構いません。
* **契約言語一覧** — 参考表示用の項目です（保存しなくても動作に影響しません）。実際に使用される契約言語は、必ずtranser.io側の「ドメイン管理」でのご登録内容が優先されます。
* **translate.serviceのURL** — 通常は変更不要です（既定値: `https://api.transer.io`）。

3. 保存が完了すれば設定は完了です。プラグイン自体の追加設定は不要です。

> **重要:** プラグイン側の設定を済ませても、transer.io管理画面の「ドメイン管理」でホスト名・契約言語を登録していない場合、翻訳リクエストは403エラーで拒否されます。プラグインの設定と、ダッシュボードでのドメイン登録は、両方とも必要です。

4. サイトに言語切り替えUI（言語ボックス。ページ返却時にサーバー側から自動挿入されます）
   が表示されます。訪問者が言語を選ぶと、WordPressサイトの場合は仮想URL
   （例: `/en/about`）へ、それ以外の環境の場合はURLに`?hl=en`を付けて
   ページ遷移し、翻訳済みページが表示されます（Cookieは使用しません）。

== Frequently Asked Questions ==

= hreflangタグの本数はどう決まりますか? =

設定画面で指定した「契約言語一覧」の数に応じて、サーバー側が自動生成します。
言語を追加・削除すれば、次回以降のリクエストから反映されます。

= 通信に失敗した場合どうなりますか? =

原文（日本語ページ）がそのまま表示されます。サイトの表示自体が止まることはありません。

== External services ==

This plugin connects to transer.io's translation server (translate.service) to
translate your page content on the fly. This is a required part of the plugin's
core functionality (there is no local/offline translation mode).

**What is sent, and when:**
This plugin only contacts the external service when either (a) the requested
URL itself begins with one of your contracted language codes as a path
prefix (for example `https://example.com/en/about` — this allows search
engines and visitors following `hreflang`/`canonical` links to reach a
translated page directly), or (b) the URL contains an `?hl=xx` query
parameter matching one of your contracted language codes (used by the
on-page language switcher for environments where the path-prefix method
above cannot be guaranteed to work). No cookie is used or set by this
plugin. On such requests, the following data is sent:

* The full HTML content of the page being rendered (so it can be translated
  and returned with translated content in place).
* Your site's hostname and the current request path (used to identify the
  page, for generating `hreflang`/`canonical` SEO tags and for account-based
  usage tracking).
* The list of contracted target languages you configured (used to generate
  `hreflang`/`canonical` SEO tags in the returned HTML).
* Your transer.io API key (sent via the `Authorization` header, for
  authentication and account-based usage tracking).

**Where it is sent:**
By default, requests are sent to `https://api.transer.io/translate-page`
(transer.io's translation service). You can point this at a different URL
in the plugin settings if instructed to do so by transer.io support.

**Service provider:**
transer.io — [https://transer.io](https://transer.io)
Terms of Service / Privacy Policy: please refer to the legal pages published
on https://transer.io (linked from the transer.io account dashboard).

If neither a contracted-language URL path prefix nor a matching `?hl=`
query parameter is present (e.g. a visitor viewing the site in its original
language at its normal URL), this plugin makes no external requests at all.

== Changelog ==

= 0.6.0 =
* 言語ボックス（langbox.js）が、ページソースの内容からWordPressサイト
  かどうかを自動判定するようになりました。WordPressの場合は、SEO対応
  として用意済みの仮想URL（`/en/about`等）へ実際にページ遷移します
  （Cookie・クエリパラメータは一切使用しません）。

= 0.5.0 =
* 言語ボックス（langbox.js）のURL形式を、クエリパラメータ方式
  （`?lang=ja`）に変更しました。契約している翻訳先言語一覧は、
  langbox.js側がリクエスト元ドメインから自動的に判定するため、
  `data-langs`属性による指定は不要になりました（ダッシュボードで
  契約言語を変更しても、以前のように手動でタグを書き換える必要が
  なくなりました）。
* Cookie（`transer_lang`）に契約していない言語コードが指定された
  場合、原文（日本語）ページに言語ボックスが表示されなくなる不具合
  を修正しました。ブラウザの開発者ツール等でCookieの値を自由に
  書き換えられても、必ず契約言語一覧と照合してから判定するように
  なりました。

= 0.4.0 =
* `hreflang`/`canonical`タグが指す言語プレフィックス付きURL（例: `/en/about`）に
  Cookie無しで直接アクセスされた場合も、正しく翻訳済みページが表示されるように
  なりました（検索エンジンのクローラーや、検索結果から直接訪問した場合に対応）。
* 契約言語を設定・変更するたびに、この仮想URLをWordPress本体が処理できる
  実ページへ内部的に振り分けるルールを、サイトの`.htaccess`へ自動で追記・更新
  するようになりました（サーバーの設定ファイルを直接編集できない共有レンタル
  サーバー環境でも、追加の作業は不要です）。追記内容はプラグイン専用の目印で
  管理されるため、WordPress本体や他プラグインのルールには影響しません。

= 0.1.0 =
* 初版リリース。

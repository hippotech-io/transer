=== Transer Translate ===
Contributors: transerio
Tags: translation, multilingual, seo, hreflang
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ページ全体をサーバーサイドで翻訳し、多言語SEOタグ・言語ボックス付きで返すtranser.io連携プラグイン。

== Description ==

Transer Translate は、WordPressサイトのページ全体を transer.io の翻訳サーバー
（translate.service）へ送信し、翻訳済みHTML（canonical/hreflangタグ・言語ボックス
用スクリプト付き）を受け取って差し替えるプラグインです。

* `hreflang`/`canonical`タグは契約言語数に応じてサーバー側が自動生成します。
* 通信に失敗した場合は原文をそのまま表示します（翻訳不可より表示優先）。

= 動作確認済みの環境 =

* キャッシュプラグイン（WP Super Cache）と問題なく共存することを確認しています。
  Cookie・クエリパラメータを一切使わず、URLパス（`/en/about`等）のみで言語を
  判定する設計のため、URL単位でキャッシュを分けるプラグイン・CDNであれば、
  基本的に正しく別々にキャッシュされます。

= 他の多言語プラグインとの併用について =

**WPML・Polylang等、URLの言語プレフィックスで多言語化を行う他のプラグインとは、
同時に有効化しないでください。** どちらも同じ問題（言語ごとのURL振り分け）を
独自に解決しようとするため、正常に共存できません。サイトの多言語化は、
このプラグインか、WPML・Polylang等のいずれか一つでご利用ください。

== Installation ==

このプラグインの導入方法は、大きく分けて3通りあります。多くの場合は方法A（WordPress管理画面から直接検索）で十分です。

= 方法A: WordPress管理画面から直接インストールする（推奨） =

1. WordPress管理画面にログインし、「プラグイン」→「新規追加」を開きます。
2. 検索ボックスに「Transer Translate」と入力します。
3. 表示された「Transer Translate」の「今すぐインストール」をクリックします。
4. インストール完了後に表示される「有効化」ボタンをクリックします。

= 方法B: transer.io管理画面からダウンロードしたzipをアップロードする =

最新の開発版を試したい場合など、方法Aと異なるバージョンを使いたい場合の方法です。

1. transer.io の管理画面（「APIキー管理」ページ）から、プラグインのzipファイルをダウンロードします。
2. WordPress管理画面にログインし、「プラグイン」→「新規追加」を開きます。
3. 画面上部の「プラグインのアップロード」ボタンをクリックします。
4. 「ファイルを選択」から、手順1でダウンロードしたzipファイルを選び、「今すぐインストール」をクリックします。
5. インストール完了後に表示される「プラグインを有効化」ボタンをクリックします。

= 方法C: FTP/SSHでサーバーへ手動配置する =

サーバーへの直接アクセス手段をお持ちの方向けの方法です。

1. このプラグインのフォルダ一式を、そのまま `wp-content/plugins/transer-translate/` に配置します
   （`transer-translate.php` と `readme.txt` が直下に来るようにしてください）。
2. WordPress管理画面の「プラグイン」一覧から「Transer Translate」を有効化します。

= 有効化後の設定 =

いずれの方法でインストールした場合も、有効化後の設定手順は共通です。

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

= キャッシュプラグインと併用できますか? =

はい。Cookie・クエリパラメータを使わず、URLパスのみで言語を判定するため、
WP Super Cache等のキャッシュプラグインと問題なく共存することを確認しています。

= WPML・Polylang等の多言語プラグインと併用できますか? =

いいえ、併用しないでください。どちらも同じ問題（URLでの言語振り分け）を
独自に解決しようとするため、正常に共存できません。

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

= 0.7.4 =
* キャッシュプラグイン（WP Super Cache）との共存を実機で確認し、readme.txtに
  動作確認済みである旨を追記しました。
* WPML・Polylang等、URLの言語プレフィックスで多言語化を行う他のプラグインとは
  併用しないよう、readme.txt（Description・FAQ）に明記しました。

= 0.7.3 =
* WordPress.org公式プラグインディレクトリへの提出に向けた準備を行いました。
* 管理画面の全文言を国際化(i18n)対応しました（`Text Domain`宣言はしていたが
  翻訳関数を一切使っていなかった不整合を修正）。
* `readme.txt`の`Tested up to`を実際に検証したバージョンに更新しました。
* WordPress.org経由でのインストール手順（管理画面から直接検索してインストール）
  を「インストール」セクションに追記しました。

= 0.7.2 =
* 設定画面で契約言語一覧を保存する際、`register_setting()`のsanitize処理が
  環境により2回連続で発火することがあり、その場合`contract_langs`が文字列
  "Array"1件だけの無効な値に化けてしまう不具合を修正しました（PHPの配列を
  文字列にキャストすると"Array"になるという既知の挙動が原因）。
  実際に設定は正しく入力していたのに、URLプレフィックスでの言語判定が
  一切機能しない、という形で発覚しました。
* 上記の不具合で既に壊れた値が保存されている場合に備え、管理画面アクセス時
  に自動的に修復する処理を追加しました（手動での再設定は不要です）。

= 0.7.1 =
* v0.7.0で追加したURLプレフィックス判定が、実際には一切機能していなかった
  不具合を修正しました。WordPressが `$wp->request` を計算する**前**に発火する
  `do_parse_request` フィルタ内でその値を読もうとしていたため、常に空の値
  しか取得できていませんでした。`init` フックの早い段階で
  `$_SERVER['REQUEST_URI']` 自体を書き換える方式に変更し、正しく動作する
  ようになりました。

= 0.7.0 =
* 言語プレフィックス付きURL（`/en/about`等）の判定方式を、`.htaccess`への
  自動書き換え（v0.4.0で追加）から、WordPress自身のURL解決より前に
  プレフィックスを検出・除去する方式（`do_parse_request`フック）に変更しました。
* これにより、サーバー側のRewrite設定（`.htaccess`への書き込み含む）が
  一切不要になりました。共有レンタルサーバー・nginx環境を問わず、
  パーマリンク構造にも影響されず動作します。
* 有効化時に、v0.4.0〜v0.6.xが`.htaccess`に自動追記していた旧ルールを
  自動的に削除するようになりました（手動での削除は不要です）。

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

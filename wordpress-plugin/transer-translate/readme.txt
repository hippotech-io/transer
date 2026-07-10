=== Transer Translate ===
Contributors: transerio
Tags: translation, multilingual, seo, hreflang
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
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

1. このプラグインを `wp-content/plugins/transer-translate/` に配置します。
2. WordPress管理画面の「プラグイン」から有効化します。
3. 「設定 > Transer Translate」で、APIキー・契約言語一覧を設定します。
4. サイトに言語切り替えUI（言語ボックス。ページ返却時にサーバー側から自動挿入されます）
   を通じて `transer_lang` Cookieがセットされると、以降そのブラウザには翻訳済み
   ページが返されます。

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
This plugin only contacts the external service when a visitor's browser has a
`transer_lang` cookie set to a language other than your configured source
language (default: `ja`). On such requests, the following data is sent:

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

If no non-source-language cookie is present (e.g. a visitor viewing the
site in its original language), this plugin makes no external requests at all.

== Changelog ==

= 0.1.0 =
* 初版リリース。

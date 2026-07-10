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

* ページ単位のキャッシュ・差分翻訳はサーバー側で行われるため、内容が変わらない
  ページの再アクセスは高速に返ります。
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

= 翻訳結果はどこにキャッシュされますか? =

translate.service側（Redis + S3）で、hostname + パス + 言語ごとにキャッシュされます。
このプラグイン自体はキャッシュを持ちません。

= 通信に失敗した場合どうなりますか? =

原文（日本語ページ）がそのまま表示されます。サイトの表示自体が止まることはありません。

== Changelog ==

= 0.1.0 =
* 初版リリース。

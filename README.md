# transer

Nginx/Apache2配下のアプリケーションから、日本語ページを多言語に翻訳するための
クライアントライブラリです。実際の翻訳処理（文脈解析・翻訳・HTML再構築）は
すべてサーバー側(translate.service)で行われ、利用者はHTMLを渡して
翻訳済みHTMLを受け取るだけです。

> **WordPressをお使いの場合**: このリポジトリには、自分でコードを書かずに
> 導入できる **WordPressプラグイン** も同梱されています（`wordpress-plugin/`
> ディレクトリ）。インストール手順・仕組みは
> [wordpress-plugin/README.md](./wordpress-plugin/README.md) を参照してください。
> 以下のPython向け説明は、自前のアプリ・翻訳プロキシに組み込みたい場合の内容です。

## v0.3.1での変更点

- **言語ボックス用スクリプトを `trsv2.js` から `langbox.js` に統一**。
  URL・仕様の詳細は下記「言語ボックス・多言語SEOタグについて」を参照。
- **原文（日本語）ページに、常設の言語切り替えボックスを設置する方法を追加**
  （下記「サイト全体に組み込む」参照）。これまでは翻訳済みページにしか
  言語ボックスが表示されず、初回訪問者が言語を切り替える手段が無い、という
  問題があったため追加しました。
- **Cookie名を `lang` から `transer_lang` に統一**（WordPressプラグイン・
  言語ボックススクリプトと共通の名前にするため）。旧バージョンの実装例を
  そのまま使っている場合は、後述の「移行時の注意」を参照してください。
- **契約していない言語へのリクエストは403で拒否されるようになりました**
  （以前は、訪問者のCookieに残った古い言語コードなどで契約外言語への
  翻訳リクエストが素通りしてしまう問題がありました）。

### 移行時の注意（v0.3.0以前からのアップグレード）

既に `lang` というCookie名で翻訳プロキシを実装している場合、そのままでは
言語ボックス（`langbox.js`）と食い違い、言語切り替えが正しく動作しません。
お手数ですが、ご自身の翻訳プロキシコード内の `request.cookies.get("lang", ...)`
を `request.cookies.get("transer_lang", ...)` に書き換えてください。

## v0.3.0での変更点

- `Translator(contract_langs=[...])` を追加。契約している翻訳先言語コードの一覧を
  渡せるようになった（`hreflang` alternate タグを何本出すかに使われる）。
- サーバー側で自動挿入される「言語ボックス・多言語SEOタグ」についてREADMEに説明を追加
  （コードの変更ではなく、既存動作の言語化。詳細は下記セクション参照）。

## 言語ボックス・多言語SEOタグについて（Module/Pluginの中核機能）

`translate_page()` が返すHTMLの `<head>` には、以下が**サーバー側で自動的に**
挿入されます。利用者側で個別に実装する必要はありません。

```html
<link rel="canonical" href="https://example.com/en/"/>
<link rel="alternate" hreflang="x-default" href="https://example.com/"/>
<link rel="alternate" hreflang="ja" href="https://example.com/"/>
<link rel="alternate" hreflang="en" href="https://example.com/en/"/>
<link rel="alternate" hreflang="zh-TW" href="https://example.com/zh-TW/"/>
<script src="https://api.transer.io/js/langbox.js"
        data-source="ja" data-langs="en,zh-TW" async></script>
```

### canonical / hreflang alternate タグ

- **原文言語（source_lang、通常は`ja`）はURLにプレフィックスが付きません。**
  `contract_langs` に指定したそれ以外の言語は `/{lang}/` プレフィックス付きのURLになります
  （例: 英語なら `https://example.com/en/about`）。これは
  「言語ごとに別URLを持つ」という、Googleが推奨する多言語サイト構成に対応するためです。
- **`canonical`** は「今まさに配信している言語」自身のURLを指します。
  英語版ページを返しているリクエストなら、`canonical` は英語版のURLになります
  （日本語版を指すわけではありません）。
- **`hreflang="x-default"`** は、ユーザーの言語設定がどの`hreflang`とも一致しなかった場合に
  検索エンジンやブラウザが「代表として」使うべきページを示すための、`hreflang`の特別な予約値です。
  本サービスでは常に**原文言語（`ja`）のURL**を`x-default`として指定します。
- 出力される`hreflang` alternate の本数は、`Translator(contract_langs=[...])` で
  指定した言語の数（+ 原文言語 + x-default）になります。契約言語を増減すれば、
  次回以降のリクエストから自動的にタグの本数も追従します。
- **契約していない言語をリクエストすると403エラーになります。** ダッシュボードの
  「ドメイン管理」で契約している言語と、実際にリクエストする`target_lang`が
  一致しているか確認してください。

### 言語ボックス用スクリプト（langbox.js）

`<head>`の一番下に、言語切り替えUI（言語ボックス）を描画するスクリプトが
自動挿入されます。ただし、これは**翻訳済みページにのみ**挿入されます。

> ⚠️ **重要**: 原文（日本語）ページは`translate.service`を一切経由しないため、
> このままでは訪問者が最初に言語を切り替える手段（言語ボックス）が
> どこにも表示されません。原文ページにも言語ボックスを表示するには、
> 下記「サイト全体に組み込む」の手順3（言語ボックスの設置）を行ってください。

## v0.2.0での変更点（v0.1.xからの移行）

- `translate_page()` は **`hostname`（`Translator`構築時）・`pathname`（呼び出し時）が必須** になりました。
  ページの識別（多言語SEOタグの生成、アカウント単位の利用管理）に使われます。
- `translate_page()` の呼び出しには **APIキーが必須** になりました
  （アカウント単位で利用を管理するため）。

## インストール

### 動作環境

- Python **3.9以上**（Ubuntu 20.04以降を推奨。16.04等のサポート終了OSは非推奨）
- `httpx` 0.24以上（インストール時に自動で入ります）

### ⚠️ 作業ディレクトリについて（必ずお読みください）

`venv`の作成・`pip install`は、**`sudo`を付けずに実行してください。**
また、`/var/www/html`のように`root`や`www-data`が所有するディレクトリの中で
作業するのは避け、**自分のユーザーが所有するディレクトリ**（例:
`~/proxy-app`）で作業してください。

`sudo python3 -m venv venv`のようにsudo付きで作成すると、作られたファイルの
所有者が`root`になり、その後の`pip install`やサービス起動で権限エラーが
発生します。もし途中まで進めてしまった場合は、`venv`フォルダを削除して
sudo無しで作り直してください。

```bash
mkdir -p ~/proxy-app
cd ~/proxy-app

python3 -m venv venv
source venv/bin/activate

pip install "git+https://github.com/hippotech-io/transer.git@v0.3.1"
```

## 使い方

```python
from transer import Translator

translator = Translator(
    base_url="https://api.transer.io",   # 翻訳サーバー(translate.service)のURL
    api_key="発行されたAPIキー",           # translate_page() を使う場合は必須
    hostname="example.com",              # このサイトのホスト名（ページ識別に使われる）
    contract_langs=["en", "zh-TW", "ko"],  # 契約している翻訳先言語（hreflangタグの本数に使われる）
)

# ページ全体を翻訳する（最も一般的な使い方）
translated_html = await translator.translate_page(
    html=original_html,
    pathname="/about",     # このページのパス（ページ識別に使われる）
    source_lang="ja",
    target_lang="en",
)

# テキストの配列だけを翻訳したい場合（hostname不要）
result = await translator.translate(["こんにちは", "元気ですか"])
# -> [{"original": "こんにちは", "translated": "Hello"}, ...]
```

## サイト全体に組み込む（推奨構成）

`transer`はHTMLを渡すと翻訳済みHTMLを返すだけのクライアントです。
「ブラウザからのリクエストを受けて、元のページを取得し、`transer`で翻訳して返す」
という橋渡し役（以下では"翻訳プロキシ"と呼びます）は、**利用者側のアプリとして
別途実装していただく必要があります**。以下は、実機で動作確認済みの最小構成です。

```
[ブラウザ] → [Nginx] → [翻訳プロキシ（proxy.py）] ─┬─ transer_langがja/未設定 ──→ 既存サイトのHTMLをそのまま返す
                                                    └─ transer_langがen等 ─────→ 既存サイトのHTMLを取得 → transerで翻訳して返す
```

上記の通り、振り分け自体もこのプロキシアプリ1つが行います（nginx側で
複数バックエンドに振り分ける必要はありません。実装がシンプルになり、
かつ実際にこの構成で動作確認済みです）。

日本語ユーザーもこのプロキシを経由しますが、`transer`（＝`translate.service`
への通信）を呼び出すのは他言語の場合のみなので、日本語ユーザーへの
パフォーマンス影響はありません。

### 1. 翻訳プロキシ（FastAPI実装例）

```python
# proxy.py
import httpx
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse
from transer import Translator

app = FastAPI()

translator = Translator(
    base_url="https://api.transer.io",
    api_key="発行されたAPIキー",
    hostname="example.com",   # このサイトのホスト名を固定で指定
    contract_langs=["en", "zh-TW", "ko"],  # 契約している翻訳先言語
)

ORIGIN_BASE_URL = "http://127.0.0.1:8080"  # 既存サイト(Apache2/PHP-FPM等)


@app.api_route("/{path:path}", methods=["GET"])
async def proxy(request: Request, path: str):
    # Cookie名は "transer_lang" で統一すること（言語ボックス・WordPress
    # プラグインと共通の名前。ここが食い違うと言語切り替えが動作しない）
    lang = request.cookies.get("transer_lang", "ja")

    async with httpx.AsyncClient() as client:
        origin_resp = await client.get(
            f"{ORIGIN_BASE_URL}/{path}",
            params=request.query_params,
        )

    html = origin_resp.text
    if lang != "ja":
        pathname = "/" + path
        html = await translator.translate_page(
            html, pathname=pathname, source_lang="ja", target_lang=lang,
        )

    return HTMLResponse(html, status_code=origin_resp.status_code)
```

### 2. systemdで常駐化

```ini
# /etc/systemd/system/proxy-app.service
[Unit]
Description=翻訳プロキシアプリ
After=network.target
# 短時間に何度も再起動を繰り返す場合は「失敗」として明確に停止させる。
# これが無いと、ポート競合等の問題が起きても気づかず延々と再起動を
# 繰り返し続けてしまうことがある。
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
User=あなたのユーザー名
WorkingDirectory=/home/あなたのユーザー名/proxy-app
ExecStart=/home/あなたのユーザー名/proxy-app/venv/bin/uvicorn proxy:app --host 127.0.0.1 --port 8090
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now proxy-app.service
sudo systemctl status proxy-app.service --no-pager
```

> ⚠️ **動作確認のため`uvicorn`をターミナルで直接手動実行するのは避けてください。**
> ターミナルを閉じ忘れる等で残り続けると、ポートを占有したままになり、
> 正規のsystemdサービスが「ポート使用中」で永久に再起動を繰り返す原因になります。
> 動作確認は必ず`systemctl restart proxy-app.service`経由で行ってください。

### 3. 言語ボックスの設置（原文ページ用）

上記のプロキシは、既に`transer_lang`Cookieが指定された後にページを翻訳する
仕組みです。原文（日本語）ページには、訪問者が言語を切り替えるためのUIが
まだ存在しません。既存サイトの`</body>`直前に、以下の1行を追加してください。

```html
<script src="https://api.transer.io/js/langbox.js"
        data-source="ja"
        data-langs="en,zh-TW,ko"
        async></script>
```

| 属性 | 内容 |
|---|---|
| `data-source` | 原文言語コード（通常は`ja`） |
| `data-langs` | 契約している翻訳先言語コード（カンマ区切り） |

> ⚠️ **`data-langs`は、ダッシュボードで契約している言語と手動で一致させてください。**
> ダッシュボード側で言語を追加・削除しても、このタグの中身は**自動更新されません**。
> 言語を変更した際は、忘れずにこのタグも書き換えてください。

このスクリプトは、選ばれた言語に応じて`transer_lang`Cookieをセットして
ページを再読み込みするだけの軽量なものです（`translate_page()`自体は
呼び出しません）。翻訳済みページには、同じ見た目の言語ボックスが
`translate.service`から自動的に埋め込まれるため、原文・翻訳済みどちらでも
同じ操作感になります。また、**原文言語（`ja`）を選び直すと`transer_lang`
Cookie自体が削除される**ため、何らかの理由でCookieと表示内容がズレて
しまった場合も、日本語に切り替え直すだけで確実にリセットできます。

### 4. Nginxの設定

```nginx
server {
    listen 80;
    server_name example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name example.com;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx

# SSL証明書がまだ無い場合
sudo certbot --nginx -d example.com
```

## 動作確認

1. シークレットウィンドウ（Cookie無し）で自分のサイトを開く
2. 画面右下に言語ボックスが表示されるか確認
3. 言語を切り替える → 自動でリロード → 翻訳されて表示されるか確認
4. 切り替え後のページでも言語ボックスが引き続き表示されているか確認
5. ページのソース（`Ctrl+U`）で、`<head>`に`canonical`/`hreflang`タグと
   `langbox.js`のscriptタグが入っているか確認

## トラブルシューティング

| 症状 | 原因・対処 |
|---|---|
| `python3 -m venv`・`pip install`で`Permission denied` | `/var/www/html`等、rootまたはwww-data所有のディレクトリで作業しようとしている。自分のユーザーが所有するディレクトリ（例: `~/proxy-app`）で作業すること |
| 502 Bad Gateway | 翻訳プロキシ（`proxy-app.service`）が起動していない、またはクラッシュしている。`systemctl status`で確認 |
| `address already in use`で再起動を繰り返す | 同じポートを、手動実行した古い`uvicorn`プロセス等が既に掴んでいる。`sudo ss -tlnp \| grep <ポート番号>`で該当プロセスを特定し終了させてから、サービスを再起動する |
| 403エラー（hostname未登録） | ダッシュボードの「ドメイン管理」の登録ホスト名と、`proxy.py`の`hostname`が完全一致しているか確認（www有無等） |
| 403エラー（契約されていません） | リクエストした言語が、ダッシュボードで契約している言語と一致していない。訪問者のCookieに古い言語コードが残っている場合もこの動きになる |
| 言語ボックスが原文ページに出ない | 手順3のscriptタグを埋め込んでいるか確認。ブラウザのNetworkタブで`langbox.js`が読み込まれているか（404になっていないか）確認 |
| 言語ボックスに一部の言語しか出ない | `data-langs`属性が、ダッシュボードの最新の契約言語と一致していない（自動更新されないため、変更時は手動で書き換える） |
| 翻訳後のページで言語ボックスが消える | `langbox.js`自体が404になっていないか確認（`curl -I https://api.transer.io/js/langbox.js`） |
| Cookieと表示内容がズレる | 言語ボックスで一度「日本語（原文言語）」を選び直すと、Cookieがリセットされ直る |
| 通信失敗時 | プロキシは失敗時に原文をそのまま返す設計。サイト自体が止まることは無い |

## 注意事項

- 通信に失敗した場合、`translate_page()` は例外を投げずに**元のHTMLをそのまま返します**
  （翻訳できないより、原文が表示される方が実運用上望ましいという判断です）。
- `api_key` または `hostname` が未設定のまま `translate_page()` を呼んだ場合は
  `TranslerError` が送出されます（これは通信失敗ではなく設定不備のため、
  上記のフォールバックとは区別されます＝原文にはフォールバックしません）。
- `translate()`（低レベルAPI）は通信失敗時に例外(`TranslerError`)を送出します。
- 課金は翻訳文字数ではなく、契約言語数に基づく方式です。

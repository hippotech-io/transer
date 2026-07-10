# transer

Nginx/Apache2配下のアプリケーションから、日本語ページを多言語に翻訳するための
クライアントライブラリです。実際の翻訳処理（文脈解析・翻訳・HTML再構築）、および
**ページ単位のキャッシュ・差分翻訳**は、すべてサーバー側(translate.service)で行われ、
利用者はHTMLを渡して翻訳済みHTMLを受け取るだけです。

> **WordPressをお使いの場合**: このリポジトリには、自分でコードを書かずに
> 導入できる **WordPressプラグイン** も同梱されています（`wordpress-plugin/`
> ディレクトリ）。インストール手順・仕組みは
> [wordpress-plugin/README.md](./wordpress-plugin/README.md) を参照してください。
> 以下のPython向け説明は、自前のアプリ・翻訳プロキシに組み込みたい場合の内容です。

## v0.3.1での変更点

- **WordPressプラグイン（`transer-translate`）を追加**（`wordpress-plugin/`）。
  Pythonコードを書かずに、管理画面の設定だけで同じ `translate.service` API
  (`/translate-page`) を利用できる。詳細は
  [wordpress-plugin/README.md](./wordpress-plugin/README.md) を参照。
  ※ Pythonパッケージ(`transer`)自体のコード変更はありません。

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
<script src="https://api.transer.io/js/trsv2.js?lang=ja"></script>
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
  つまり「どの言語にも該当しない場合は日本語版を代表とする」という意味になります。
  `hreflang="ja"`（明示的な日本語向けタグ）とURLは同じになりますが、両方とも出力されます
  （`x-default`は「言語不問のフォールバック先」、`hreflang="ja"`は「日本語ユーザー向け」という
  役割が異なるため、検索エンジン向けに両方明示するのが一般的な作法です）。
- 出力される`hreflang` alternate の本数は、`Translator(contract_langs=[...])` で
  指定した言語の数（+ 原文言語 + x-default）になります。契約言語を増減すれば、
  次回以降のリクエストから自動的にタグの本数も追従します。

### 言語ボックス用スクリプト（trsv2.js）

`<head>`の一番下に、ページ編集ツール付きの言語切り替えUI（言語ボックス）を描画する
スクリプトが自動挿入されます。`lang=`パラメータは常に原文言語（`ja`）固定です。
このスクリプト自体の詳細な機能（辞書登録・直接編集・画像置換・翻訳禁止など）は
別途ドキュメント化予定です。

## v0.2.0での変更点（v0.1.xからの移行）



- `translate_page()` は **`hostname`（`Translator`構築時）・`pathname`（呼び出し時）が必須** になりました。
  サーバー側で `hostname + pathname + 言語` をキーにページ単位のキャッシュ・差分翻訳を行うためです。
- **ページキャッシュはサーバー側で完結するようになりました。** v0.1.x時点では
  「同一ページへの再アクセスをキャッシュしたい場合は利用者側(翻訳プロキシ側)で
  実装してください」という制約がありましたが、これは撤廃されました。
  同じ `hostname + pathname + 言語` へのリクエストは、ページ内容に変化が無ければ
  サーバー側のキャッシュから即座に返り、変化があった分だけ再翻訳されます。
- `translate_page()` の呼び出しには **APIキーが必須** になりました
  （ページキャッシュ・アカウント管理をAPIキー単位で行うため）。

## インストール

```bash
pip install /path/to/transer   # ローカルパッケージから
# もしくは
pip install "git+https://github.com/hippotech-io/transer.git@v0.3.0"
```

## 使い方

```python
from transer import Translator

translator = Translator(
    base_url="https://api.transer.io",   # 翻訳サーバー(translate.service)のURL
    api_key="発行されたAPIキー",           # translate_page() を使う場合は必須
    hostname="example.com",              # このサイトのホスト名（ページキャッシュのキーに使われる）
    contract_langs=["en", "zh-TW", "ko"],  # 契約している翻訳先言語（hreflangタグの本数に使われる）
)

# ページ全体を翻訳する（最も一般的な使い方）
translated_html = await translator.translate_page(
    html=original_html,
    pathname="/about",     # このページのパス（ページキャッシュのキーに使われる）
    source_lang="ja",
    target_lang="en",
)

# テキストの配列だけを翻訳したい場合（hostname不要・ページキャッシュ無し）
result = await translator.translate(["こんにちは", "元気ですか"])
# -> [{"original": "こんにちは", "translated": "Hello"}, ...]
```

## サイト全体に組み込む（推奨構成）

`transer`はHTMLを渡すと翻訳済みHTMLを返すだけのクライアントです。
「ブラウザからのリクエストを受けて、元のページを取得し、`transer`で翻訳して返す」
という橋渡し役（以下では"翻訳プロキシ"と呼びます）は、**利用者側のアプリとして
別途実装していただく必要があります**。以下は最小構成の例です。

```
                          ┌─ lang=ja(またはCookie無し) ──→ 既存サイト（そのまま）
[ブラウザ] → [Nginx] ──┤
                          └─ lang=en等 ──────────────→ 翻訳プロキシ（下記実装）
                                                          │
                                                          ├─→ 既存サイトから元ページ取得
                                                          └─→ transer.Translator で翻訳して返す
```

日本語ユーザーは翻訳プロキシを経由せず既存サイトへ直接届くため、
翻訳機能を追加してもパフォーマンスへの影響はありません。

### 1. 言語ボックス（フロントエンド）

言語選択時にCookieをセットしてリロードします。

```javascript
document.getElementById('lang-select').addEventListener('change', (e) => {
  document.cookie = `lang=${e.target.value}; path=/; max-age=31536000`;
  location.reload();
});
```

### 2. 翻訳プロキシ（FastAPI実装例）

既存サイトから元のHTMLを取得し、`transer`で翻訳してそのまま返すだけの
小さなアプリです。ポート番号は既存サイト・`translate.service`と重ならない
ものを割り当ててください（例では8002）。

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
    lang = request.cookies.get("lang", "ja")

    async with httpx.AsyncClient() as client:
        origin_resp = await client.get(
            f"{ORIGIN_BASE_URL}/{path}",
            params=request.query_params,
        )

    html = origin_resp.text
    if lang != "ja":
        # pathname はページキャッシュのキーに使われるため、クエリを含まない
        # リクエストパスをそのまま渡す（"/" 始まりを想定）。
        pathname = "/" + path
        html = await translator.translate_page(
            html, pathname=pathname, source_lang="ja", target_lang=lang,
        )

    return HTMLResponse(html, status_code=origin_resp.status_code)
```

`systemd`等で常駐化してください（`translate.service`と同様の要領です）。

```ini
# /etc/systemd/system/translate-proxy.service
[Unit]
Description=Translate Proxy (transer)
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/proxy
Environment="PATH=/var/www/proxy/venv/bin"
ExecStart=/var/www/proxy/venv/bin/uvicorn proxy:app --host 127.0.0.1 --port 8002
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

### 3. Nginxの振り分け設定

```nginx
# Cookieの値によって振り分け先を決定する。
# 値が "ja" または未設定なら既存サイトへ、それ以外は翻訳プロキシへ。
map $cookie_lang $backend {
    default   translate_backend;
    ""        origin_backend;
    "ja"      origin_backend;
}

upstream origin_backend {
    server 127.0.0.1:8080;   # 既存サイト
}

upstream translate_backend {
    server 127.0.0.1:8002;   # 翻訳プロキシ(上記proxy.py)
}

server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://$backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### 4. Apache2の場合（mod_rewrite + mod_proxy版）

```apache
RewriteEngine On
RewriteCond %{HTTP_COOKIE} !lang=ja
RewriteCond %{HTTP_COOKIE} lang=([a-zA-Z-]+)
RewriteRule ^(.*)$ http://127.0.0.1:8002$1 [P,L]

ProxyPassReverse / http://127.0.0.1:8002/
```

## 注意事項

- 通信に失敗した場合、`translate_page()` は例外を投げずに**元のHTMLをそのまま返します**
  （翻訳できないより、原文が表示される方が実運用上望ましいという判断です）。
- `api_key` または `hostname` が未設定のまま `translate_page()` を呼んだ場合は
  `TranslerError` が送出されます（これは通信失敗ではなく設定不備のため、
  上記のフォールバックとは区別されます＝原文にはフォールバックしません）。
- `translate()`（低レベルAPI）は通信失敗時に例外(`TranslerError`)を送出します。
- **ページキャッシュはサーバー側(translate.service)で行われます。** 同じ
  `hostname + pathname + 言語` へのリクエストで内容に変化が無ければ、翻訳エンジンへの
  再送信は発生せず、キャッシュから高速に返されます。利用者側で追加のキャッシュ実装は
  不要です。
- 課金は翻訳文字数ではなく、契約言語数に基づく方式に移行予定です（詳細は別途案内）。

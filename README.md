# transer

Nginx/Apache2配下のアプリケーションから、日本語ページを多言語に翻訳するための
クライアントライブラリです。実際の翻訳処理（文脈解析・翻訳・HTML再構築）は
すべてサーバー側で行われ、利用者はHTMLを渡して翻訳済みHTMLを受け取るだけです。

## インストール

```bash
pip install /path/to/transer   # ローカルパッケージから
# もしくは
pip install "git+https://github.com/HippoGo530/transer.git@v0.1.0"
```

## 使い方

```python
from transer import Translator

translator = Translator(
    base_url="https://translate-api.example.com",  # 翻訳サーバーのURL
    api_key="発行されたAPIキー",                      # 未設定なら省略可
)

# ページ全体を翻訳する（最も一般的な使い方）
translated_html = await translator.translate_page(
    html=original_html,
    source_lang="ja",
    target_lang="en",
)

# テキストの配列だけを翻訳したい場合
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
    base_url="https://translate-api.example.com",
    api_key="発行されたAPIキー",
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
        html = await translator.translate_page(html, source_lang="ja", target_lang=lang)

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
- `translate()`（低レベルAPI）は失敗時に例外(`TranslerError`)を送出します。
- 翻訳サーバー側は「実際に翻訳エンジンへ送信した文字数」で課金メータリングされます。
  同一ページへの再アクセスをキャッシュして送信文字数を減らしたい場合は、
  翻訳プロキシ側（利用者側の実装）でHTMLごとキャッシュする仕組みを追加してください
  （`transer`自体はキャッシュを行いません）。


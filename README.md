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

## v0.5.0での変更点

- **契約言語一覧（`CONTRACT_LANGS`）のハードコードを廃止しました。** 新しく
  追加した`GET /contract-langs`エンドポイントへ、**毎リクエストごとに**
  問い合わせて最新の契約言語一覧を取得するようになりました。ダッシュボードで
  言語を追加・削除した場合、`proxy.py`側の再デプロイ・再起動をしなくても、
  次のリクエストから即座に反映されます。
- **言語の選択・切り替え方式を、Cookieから「URLクエリパラメータ（`?hl=en`）」
  に変更しました。** 選択した言語は一切保持されず、別のページへ移動すれば
  常に原文言語（日本語）から表示されます。
- 言語ボックス（`langbox.js`）の`data-langs`属性を復活させました
  （直前のバージョンで、langbox.js側が自動判定するという誤った前提のもと
  一時的に外していましたが、実際にはそのような自動取得は実装されていな
  かったため、言語ボックスの選択肢が表示されなくなる不具合がありました）。

### 移行時の注意（v0.4.0以前からのアップグレード）

- `Translator(...)`の`contract_langs=`引数は不要になりました（削除しても
  動作に影響しません）。
- `fetch_contract_langs()`・`build_langbox_snippet()`関数を追加し、
  `proxy()`・`/__health`・起動時セルフテストの全てで、ハードコードされた
  `CONTRACT_LANGS`ではなくこの関数の戻り値を使うように書き換えてください。
- 翻訳プロキシの言語判定を、`request.cookies.get("transer_lang", "ja")`から
  `request.query_params.get("hl", "ja")`に書き換えてください。

## v0.4.0での変更点

- **「サイト全体に組み込む」のサンプルコードに、深刻な不具合があったため修正しました。**
  従来のサンプルは、原文（日本語）ページに対して`translate_page()`を
  呼ばない設計になっていたため、**言語ボックス自体が原文ページに一切
  表示されず、訪問者が言語を切り替える手段が無い**状態になっていました
  （`hreflang`/`canonical`タグが指すURLも、実際には解決できていませんでした）。
  新しいサンプルでは、原文ページには`translate_page()`を呼ばずに
  言語ボックスのスクリプトタグだけを直接挿入する方式に修正しています
  （`target_lang`に原文言語自体を指定すると契約言語チェックで403になるため）。
- **起動時セルフテスト・`/__health`エンドポイントの例を追加。** 設定ミス
  （APIキー・ホスト名・契約言語の書き換え忘れ等）に、起動ログや`curl`一発で
  気づけるようにするための、動作確認用の仕組みです。
- **明示的なキャッシュ無効化ヘッダー（`Cache-Control: no-store`等）をサンプル
  コードに追加。** リクエストごとに内容が変わるページのため。

### 移行時の注意（v0.3.1以前からのアップグレード）

既存の翻訳プロキシに、原文（日本語）ページへの言語ボックス挿入処理が
無い場合、上記サンプルの`else`節（`build_langbox_snippet()`の挿入部分）を
追加してください。既に動いているプロキシ自体を作り直す必要はありません。

## v0.3.1での変更点

- **言語ボックス用スクリプトを `trsv2.js` から `langbox.js` に統一**。
  URL・仕様の詳細は下記「言語ボックス・多言語SEOタグについて」を参照。
- **原文（日本語）ページに、常設の言語切り替えボックスを設置する方法を追加**
  （下記「サイト全体に組み込む」参照）。これまでは翻訳済みページにしか
  言語ボックスが表示されず、初回訪問者が言語を切り替える手段が無い、という
  問題があったため追加しました。
- **契約していない言語へのリクエストは403で拒否されるようになりました**
  （以前は、古い言語コードなどで契約外言語への翻訳リクエストが素通りして
  しまう問題がありました）。

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
<script src="https://api.transer.io/js/langbox.js?lang=ja" data-langs="en,zh-TW" charset="utf-8"></script>
```

### canonical / hreflang alternate タグ

- **原文言語（source_lang、通常は`ja`）はURLにプレフィックスが付きません。**
  契約している翻訳先言語は `/{lang}/` プレフィックス付きのURLになります
  （例: 英語なら `https://example.com/en/about`）。これは
  「言語ごとに別URLを持つ」という、Googleが推奨する多言語サイト構成に対応するためです。
- **`canonical`** は「今まさに配信している言語」自身のURLを指します。
  英語版ページを返しているリクエストなら、`canonical` は英語版のURLになります
  （日本語版を指すわけではありません）。
- **`hreflang="x-default"`** は、ユーザーの言語設定がどの`hreflang`とも一致しなかった場合に
  検索エンジンやブラウザが「代表として」使うべきページを示すための、`hreflang`の特別な予約値です。
  本サービスでは常に**原文言語（`ja`）のURL**を`x-default`として指定します。
- 出力される`hreflang` alternate の本数は、ダッシュボードの「ドメイン管理」で
  契約している言語の数（+ 原文言語 + x-default）になります。契約言語を増減すれば、
  次回以降のリクエストから自動的にタグの本数も追従します。
- **契約していない言語をリクエストすると403エラーになります。** ダッシュボードの
  「ドメイン管理」で契約している言語と、実際にリクエストする`target_lang`が
  一致しているか確認してください。

### 言語ボックス用スクリプト（langbox.js）

`<head>`の一番下に、言語切り替えUI（言語ボックス）を描画するスクリプトが
自動挿入されます。ただし、これは**翻訳済みページにのみ**挿入されます。

> ⚠️ **重要**: 原文（日本語）ページは`translate.service`を一切経由しないため、
> このままでは訪問者が最初に言語を切り替える手段（言語ボックス）が
> どこにも表示されません。下記「サイト全体に組み込む」のサンプルコード
> （v0.4.0以降）では、この点はプロキシ側で自動的に対応済みです。

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

pip install "git+https://github.com/hippotech-io/transer.git@v0.5.0"
```

## 使い方

```python
from transer import Translator

translator = Translator(
    base_url="https://api.transer.io",   # 翻訳サーバー(translate.service)のURL
    api_key="発行されたAPIキー",           # translate_page() を使う場合は必須
    hostname="example.com",              # このサイトのホスト名（ページ識別に使われる）
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
[ブラウザ] → [Nginx] → [翻訳プロキシ（proxy.py）] ─┬─ ?hl が無い/ja ──→ 既存サイトのHTMLをそのまま返す
                                                    └─ ?hl=en 等 ─────→ 既存サイトのHTMLを取得 → transerで翻訳して返す
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
# ※ サポートページ（module-support-python.html）掲載のコード例そのまま。
#    API_KEY・HOSTNAME は、実際にダッシュボードで発行/登録した値に
#    書き換えてから起動してください。
import sys

import httpx
from fastapi import FastAPI, Request
from fastapi.responses import HTMLResponse, Response
from transer import Translator

app = FastAPI()

# ▼▼▼ ここを実際にダッシュボードで発行/登録した値に書き換える ▼▼▼
API_KEY = "CHANGE_ME_TO_REAL_API_KEY"
HOSTNAME = "CHANGE_ME_TO_REGISTERED_HOSTNAME"
# ▲▲▲ ここまで ▲▲▲
BASE_URL = "https://api.transer.io"

# 書き換え忘れをその場で検知する（気づかずに起動してしまう事故を防ぐ）。
if "CHANGE_ME" in API_KEY or "CHANGE_ME" in HOSTNAME:
    print("=" * 60, flush=True)
    print("[起動エラー] API_KEY・HOSTNAME がまだ書き換えられていません。", flush=True)
    print("  このファイル上部の値を、実際にダッシュボードで発行/登録", flush=True)
    print("  した値に書き換えてから、もう一度起動してください。", flush=True)
    print("=" * 60, flush=True)
    sys.exit(1)

translator = Translator(base_url=BASE_URL, api_key=API_KEY, hostname=HOSTNAME)
ORIGIN_BASE_URL = "http://127.0.0.1:8080"  # 既存サイト（このスクリプトが用意したダミーサイト）


async def fetch_contract_langs() -> list:
    """
    このドメインの、現在の契約言語一覧を translate.service へ毎回問い合わせて取得する。
    ダッシュボードで言語を追加・削除すると、次のリクエストから即座に反映される
    （ローカルにハードコード・キャッシュしないため、同期漏れが起きない）。

    問い合わせに失敗した場合は、翻訳を行わず原文のまま返す
    （通信障害時にサイトが丸ごと止まることを防ぐため）。
    """
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(
                f"{BASE_URL}/contract-langs",
                params={"hostname": HOSTNAME},
                headers={"Authorization": f"Bearer {API_KEY}"},
            )
        if resp.status_code == 200:
            return resp.json().get("contract_langs", [])
        print(f"[contract-langs] 取得失敗（status={resp.status_code}）: {resp.text[:200]}", flush=True)
    except Exception as e:
        print(f"[contract-langs] 取得失敗（通信エラー）: {e}", flush=True)
    return []


def build_langbox_snippet(contract_langs: list) -> str:
    """原文（日本語）ページ用: 言語ボックスのスクリプトタグを組み立てる。"""
    langs_attr = ",".join(contract_langs)
    return (
        '<script src="https://api.transer.io/js/langbox.js?lang=ja" '
        f'data-langs="{langs_attr}" charset="utf-8"></script>'
    )


# 起動時、設定内容をログにはっきり出す（「どのファイルが・どの設定で」
# 動いているかを journalctl だけで確認できるようにするため）。
print("=" * 60, flush=True)
print("[起動設定]", flush=True)
print(f"  hostname    = {HOSTNAME}", flush=True)
print(f"  origin_base = {ORIGIN_BASE_URL}", flush=True)
print("  contract_langs は毎リクエストごとに translate.service へ問い合わせます", flush=True)
print("=" * 60, flush=True)


@app.on_event("startup")
async def startup_selftest():
    """
    起動直後、実際に translate.service へ1回だけ疎通確認を行い、
    成功/失敗をログにはっきり出す。
    api_key・hostname・契約言語の設定ミスは、ここで即座に判明する。
    """
    try:
        contract_langs = await fetch_contract_langs()
        if not contract_langs:
            print("[起動時セルフテスト] ✗ 失敗: 契約言語一覧を取得できませんでした", flush=True)
            print("  → api_key・hostname・「ドメイン管理」での登録状況を確認してください", flush=True)
            return

        test_html = "<html><head></head><body><p>テスト</p></body></html>"
        result = await translator.translate_page(
            test_html, pathname="/__selftest", target_lang=contract_langs[0]
        )
        if "Test" in result or len(result) > len(test_html):
            print(
                f"[起動時セルフテスト] ✓ 成功: translate.serviceと正常に通信できています"
                f"（contract_langs={contract_langs}）",
                flush=True,
            )
        else:
            print(
                f"[起動時セルフテスト] ⚠ 応答はありましたが、翻訳された形跡がありません: {result[:200]}",
                flush=True,
            )
    except Exception as e:
        print(f"[起動時セルフテスト] ✗ 失敗: {e}", flush=True)


@app.get("/__health")
async def health():
    """稼働確認用の軽量エンドポイント（curlですぐ確認できるようにするため）"""
    contract_langs = await fetch_contract_langs()
    return {
        "status": "ok",
        "hostname": HOSTNAME,
        "contract_langs": contract_langs,
        "origin_base_url": ORIGIN_BASE_URL,
    }


@app.api_route("/{path:path}", methods=["GET"])
async def proxy(request: Request, path: str):
    async with httpx.AsyncClient() as client:
        origin_resp = await client.get(f"{ORIGIN_BASE_URL}/{path}")

    content_type = origin_resp.headers.get("content-type", "")

    # 【重要】画像・CSS・JS・フォント等の静的ファイルは、翻訳処理を通さず
    # そのままバイト列で返す。ここを素通りさせないと、HTMLと同じように
    # .text で無理やり文字列化してしまい、バイナリデータが壊れてしまう
    # （画像が文字化けして表示される、CSSが読み込めない等の不具合が起きる）。
    if "text/html" not in content_type:
        return Response(
            content=origin_resp.content,
            status_code=origin_resp.status_code,
            media_type=content_type or None,
        )

    # 【重要】Cookieは使わない。選択した言語を一切覚えず、別のページへ
    # 移動すれば常に原文言語（日本語）からロードする、という仕様のため。
    # URLクエリパラメータ(?hl=en)を見る。Rewrite設定の有無を問わず、
    # どの環境でも確実に動く方式。
    lang = request.query_params.get("hl", "ja")
    html = origin_resp.text

    # 毎リクエストごとに、その時点の契約言語一覧を問い合わせて判定する
    # （ローカルのハードコードされたリストと違い、ダッシュボードでの変更が
    # 即座に反映される）。クエリパラメータの値は訪問者が自由に書き換えられる
    # ため、必ずこの一覧に実在するかどうかで判定すること。
    contract_langs = await fetch_contract_langs()

    if lang in contract_langs:
        html = await translator.translate_page(html, pathname="/"+path, target_lang=lang)
    else:
        # 原文（日本語）ページ、および契約外の値が指定された場合は、常にこちら。
        # target_lang="ja"（原文言語自体）は契約言語一覧に含まれないため、
        # translate_page()を呼ぶとサーバー側の契約言語チェックで403エラーになる。
        # そのため、原文ページには言語ボックスのスクリプトタグだけを直接挿入する
        # （WordPressプラグイン版と同じ考え方）。これが無いと、訪問者が最初に
        # 開いた日本語ページには言語を切り替える手段が一切無くなってしまう。
        if "</head>" in html:
            html = html.replace("</head>", build_langbox_snippet(contract_langs) + "</head>", 1)

    # クエリパラメータ（?hl=）によって内容が変わるページのため、ブラウザ・
    # 中間プロキシに古い言語のページがキャッシュされないよう、明示的に
    # キャッシュを無効化する。
    return HTMLResponse(
        html,
        headers={"Cache-Control": "no-store, no-cache, must-revalidate"},
    )
```

導入後は、必ず以下の順番で動作確認してください（途中を飛ばさないこと）。

1. `python3 -m py_compile proxy.py` — 構文エラーが無いことを確認
2. サービス起動後のログに `[起動時セルフテスト] ✓ 成功` が出ることを確認
3. `curl http://127.0.0.1:8090/__health` — 設定内容（hostname・contract_langs）が正しいか確認
4. `curl http://127.0.0.1:8090/ | grep langbox.js` — 原文ページに言語ボックスが挿入されているか確認
5. `curl "http://127.0.0.1:8090/?hl=en"` — 実際に翻訳された内容が返るか確認

> **編集しているファイルの場所にご注意ください**: `systemctl cat <サービス名>` で
> 表示される `WorkingDirectory` が、実際に編集しているフォルダと完全に一致しているか
> 確認してください。別の場所に同名のファイルがあると、変更が反映されないまま
> 気づかずに調査を続けてしまう事故が起きやすいです。

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
# 【多重防御】手動実行したuvicornの消し忘れ等でポートが既に使われていても、
# 起動前に自動で片付けてから起動する（"+"でroot権限実行、別ユーザーが
# 残したプロセスも対象にできる。"|| true"で、何も無い場合も起動を継続する）。
ExecStartPre=+/bin/sh -c 'fuser -k 8090/tcp || true'
ExecStartPre=/bin/sleep 1
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

上記のサンプルコード（v0.4.0以降）では、**この設置作業は不要です**。
`build_langbox_snippet()`により、プロキシ自体が原文ページの`</head>`直前に
自動でスクリプトタグを挿入します。既存サイトのテンプレートを
直接編集する必要はありません。

> 参考: 挿入される内容は以下の1行です（`data-langs`は、その時点の契約言語
> 一覧が自動で入ります）。
>
> ```html
> <script src="https://api.transer.io/js/langbox.js?lang=ja" data-langs="en,ko" charset="utf-8"></script>
> ```

| パラメータ | 内容 |
|---|---|
| `?lang=` | 原文言語コード（通常は`ja`） |
| `data-langs` | 現在の契約言語一覧（`fetch_contract_langs()`の結果がそのまま入る） |

このスクリプトは、選ばれた言語に応じて**URLに`?hl=en`のようなクエリパラメータを
付けてページ遷移する**だけの軽量なものです（`translate_page()`自体は
呼び出しません）。Cookieは一切使用しないため、別のページへ移動すれば
選択した言語は保持されず、常に原文言語（日本語）から表示されます。
翻訳済みページには、同じ見た目の言語ボックスが`translate.service`から
自動的に埋め込まれるため、原文・翻訳済みどちらでも同じ操作感になります。

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

1. ブラウザで自分のサイトを開く（原文=日本語で表示されるはず）
2. 画面右下に言語ボックスが表示されるか確認
3. 言語を切り替える → `?hl=en`等が付いてページ遷移 → 翻訳されて表示されるか確認
4. 切り替え後のページでも言語ボックスが引き続き表示されているか確認
5. ページのソース（`Ctrl+U`）で、`<head>`に`canonical`/`hreflang`タグと
   `langbox.js`のscriptタグが入っているか確認

## トラブルシューティング

| 症状 | 原因・対処 |
|---|---|
| 画像が文字化けして表示される・CSS/JSが読み込めない | サンプルコードの、`Content-Type`が`text/html`以外なら素通しする分岐（`if "text/html" not in content_type:`）が反映されているか確認してください。これが無いと、画像等の静的ファイルもテキストとして扱われ、バイナリデータが壊れてしまいます |
| `python3 -m venv`・`pip install`で`Permission denied` | `/var/www/html`等、rootまたはwww-data所有のディレクトリで作業しようとしている。自分のユーザーが所有するディレクトリ（例: `~/proxy-app`）で作業すること |
| 502 Bad Gateway | 翻訳プロキシ（`proxy-app.service`）が起動していない、またはクラッシュしている。`systemctl status`で確認 |
| `address already in use`で再起動を繰り返す | 同じポートを、手動実行した古い`uvicorn`プロセス等が既に掴んでいる。`sudo ss -tlnp \| grep <ポート番号>`で該当プロセスを特定し終了させてから、サービスを再起動する |
| 403エラー（hostname未登録） | ダッシュボードの「ドメイン管理」の登録ホスト名と、`proxy.py`の`hostname`が完全一致しているか確認（www有無等） |
| 403エラー（契約されていません） | リクエストした言語が、ダッシュボードで契約している言語と一致していない。`/contract-langs`が最新の一覧を返しているか`curl`で確認 |
| 言語ボックスが原文ページに出ない | サンプルコードの`build_langbox_snippet()`挿入部分（`else`節）が反映されているか確認。ブラウザのNetworkタブで`langbox.js`が読み込まれているか（404になっていないか）確認 |
| 翻訳後のページで言語ボックスが消える | `langbox.js`自体が404になっていないか確認（`curl -I https://api.transer.io/js/langbox.js`） |
| ダッシュボードで言語を追加したのに反映されない | `fetch_contract_langs()`が最新のコードに更新されているか確認。`curl http://127.0.0.1:<ポート>/__health`で`contract_langs`に新しい言語が含まれているか確認 |
| コードを書き換えたのに動作が変わらない | **編集しているファイルと、サービスが実際に読み込んでいるファイルが別の場所にある**典型的な事故です。`systemctl cat <サービス名>`で`WorkingDirectory`を確認し、編集しているフォルダと完全に一致しているか確認してください |
| 設定ミスの切り分けに時間がかかる | まず`curl http://127.0.0.1:<ポート>/__health`で設定内容を確認し、次に起動ログの「起動時セルフテスト」が`✓ 成功`になっているか確認してください。ここまでの2点を先に確認するだけで、原因の8割は特定できます |
| 通信失敗時 | プロキシは失敗時に原文をそのまま返す設計。サイト自体が止まることは無い |

## 注意事項

- 通信に失敗した場合、`translate_page()` は例外を投げずに**元のHTMLをそのまま返します**
  （翻訳できないより、原文が表示される方が実運用上望ましいという判断です）。
- `api_key` または `hostname` が未設定のまま `translate_page()` を呼んだ場合は
  `TranslerError` が送出されます（これは通信失敗ではなく設定不備のため、
  上記のフォールバックとは区別されます＝原文にはフォールバックしません）。
- `translate()`（低レベルAPI）は通信失敗時に例外(`TranslerError`)を送出します。
- 課金は翻訳文字数ではなく、契約言語数に基づく方式です。

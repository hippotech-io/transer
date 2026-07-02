# transer

Nginx/Apache2配下のアプリケーションから、日本語ページを多言語に翻訳するための
クライアントライブラリです。実際の翻訳処理（文脈解析・翻訳・HTML再構築）は
すべてサーバー側で行われ、利用者はHTMLを渡して翻訳済みHTMLを受け取るだけです。

## インストール

```bash
pip install /path/to/transer   # ローカルパッケージから
# もしくは
pip install git+https://github.com/yourorg/transer.git
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

## FastAPIでの利用例

```python
from fastapi import FastAPI, Request
from transer import Translator

app = FastAPI()
translator = Translator(base_url="https://translate-api.example.com")

@app.get("/{path:path}")
async def proxy(request: Request, path: str):
    lang = request.cookies.get("lang", "ja")
    html = get_original_html(path)  # 既存の日本語ページ取得処理
    if lang != "ja":
        html = await translator.translate_page(html, source_lang="ja", target_lang=lang)
    return HTMLResponse(html)
```

## 注意事項

- 通信に失敗した場合、`translate_page()` は例外を投げずに**元のHTMLをそのまま返します**
  （翻訳できないより、原文が表示される方が実運用上望ましいという判断です）。
- `translate()`（低レベルAPI）は失敗時に例外(`TranslerError`)を送出します。

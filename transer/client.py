# -*- coding: utf-8 -*-
"""
transer/client.py

利用者(Web管理者)が使うクライアントライブラリ本体。

    from transer import Translator

    translator = Translator(base_url="https://translate-api.example.com", api_key="xxx")

    # 低レベルAPI: テキスト配列を渡して翻訳配列を受け取る
    result = await translator.translate(["こんにちは", "元気ですか"])
    # -> [{"original": "こんにちは", "translated": "Hello"}, ...]

    # 高レベルAPI: HTML全体を渡して翻訳済みHTMLを受け取る
    html_out = await translator.translate_page(html, source_lang="ja", target_lang="en")

このクラスの中身は、翻訳サーバー(app.py; /translate, /translate-page)へ
HTTPリクエストを送るだけの薄い実装。文結合・抽出・翻訳・再構築といった
重い処理は一切ここには含まれない（すべてサーバー側の責任）。
"""

from __future__ import annotations

import httpx


class TranslerError(Exception):
    """transerパッケージ共通の例外"""


class Translator:
    def __init__(
        self,
        base_url: str,
        api_key: str | None = None,
        origin: str | None = None,
        timeout: float = 30.0,
    ):
        """
        Args:
            base_url: 翻訳サーバーのベースURL (例: "https://translate-api.example.com")
                       末尾に "/" が付いていても付いていなくても動作する。
            api_key:   認証用APIキー。サーバー側でAuthorizationヘッダーを
                       チェックする実装にしている場合に使う。未使用なら省略可。
            origin:    Originヘッダーとして送る値。main.py系のドメイン登録チェックを
                       使うサーバー構成の場合に指定する。今回の app.py には
                       ドメイン登録チェックの実装はないため、通常は省略可。
            timeout:   HTTPリクエストのタイムアウト秒数。
        """
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.origin = origin
        self.timeout = timeout
        self._client: httpx.AsyncClient | None = None

    # ----- 内部ユーティリティ -----

    def _get_client(self) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            self._client = httpx.AsyncClient(timeout=self.timeout)
        return self._client

    def _headers(self) -> dict:
        headers = {}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"
        if self.origin:
            headers["Origin"] = self.origin
        return headers

    async def aclose(self) -> None:
        """明示的にコネクションを閉じたい場合に呼ぶ(常駐プロセスでは通常不要)。"""
        if self._client is not None and not self._client.is_closed:
            await self._client.aclose()

    async def __aenter__(self) -> "Translator":
        return self

    async def __aexit__(self, *exc) -> None:
        await self.aclose()

    # ----- 公開API -----

    async def translate(
        self,
        texts: list[str],
        source_lang: str = "ja",
        target_lang: str = "en",
    ) -> list[dict]:
        """
        テキスト配列を渡して翻訳結果配列を受け取る低レベルAPI。

        Args:
            texts: 翻訳したい文字列のリスト。
            source_lang: 翻訳元言語コード。
            target_lang: 翻訳先言語コード。

        Returns:
            [{"original": "...", "translated": "..." | None}, ...]
            翻訳に失敗した項目は translated が None になる。
        """
        client = self._get_client()
        try:
            resp = await client.post(
                f"{self.base_url}/translate",
                json={"texts": texts, "source": source_lang, "target": target_lang},
                headers=self._headers(),
            )
            resp.raise_for_status()
        except httpx.HTTPError as e:
            raise TranslerError(f"translate request failed: {e}") from e
        return resp.json()["items"]

    async def translate_page(
        self,
        html: str,
        source_lang: str = "ja",
        target_lang: str = "en",
    ) -> str:
        """
        HTML全体を渡して翻訳済みHTMLを受け取る高レベルAPI。
        文結合・抽出・翻訳・再構築はすべてサーバー側(/translate-page)が行う。

        通信に失敗した場合は元のHTMLをそのまま返す
        (翻訳できないより、原文表示の方が実運用上望ましいため)。
        """
        client = self._get_client()
        try:
            resp = await client.post(
                f"{self.base_url}/translate-page",
                json={"html": html, "source": source_lang, "target": target_lang},
                headers=self._headers(),
            )
            resp.raise_for_status()
            return resp.json()["html"]
        except httpx.HTTPError:
            # フォールバック: 翻訳サーバーが落ちていてもサイト自体は表示させる
            return html

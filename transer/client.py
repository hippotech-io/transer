# -*- coding: utf-8 -*-
"""
transer/client.py

利用者(Web管理者)が使うクライアントライブラリ本体。

    from transer import Translator

    translator = Translator(
        base_url="https://api.transer.io",
        api_key="発行されたAPIキー",
        hostname="example.com",       # このサイトのホスト名（v0.2.0からページキャッシュのため必須）
    )

    # 高レベルAPI: HTML全体を渡して翻訳済みHTMLを受け取る
    html_out = await translator.translate_page(
        html, pathname="/about", source_lang="ja", target_lang="en"
    )

    # 低レベルAPI: テキスト配列を渡して翻訳配列を受け取る（hostname不要）
    result = await translator.translate(["こんにちは", "元気ですか"])
    # -> [{"original": "こんにちは", "translated": "Hello"}, ...]

このクラスの中身は、翻訳サーバー(translate.service; /translate, /translate-page)へ
HTTPリクエストを送るだけの薄い実装。文結合・抽出・翻訳・再構築・ページキャッシュ・
差分判定といった重い処理は一切ここには含まれない（すべてサーバー側の責任）。

── v0.2.0の変更点 ──
- translate_page() は hostname（Translator構築時）・pathname（呼び出し時）が必須になった。
  サーバー側で hostname+pathname+言語 をキーにページ単位のキャッシュ・差分翻訳を行うため。
- ページキャッシュ・差分翻訳はサーバー側（translate.service）で完結するようになったため、
  利用者側で独自にキャッシュを実装する必要はなくなった（v0.1.x時点の制約を撤廃）。
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
        hostname: str | None = None,
        origin: str | None = None,
        timeout: float = 30.0,
    ):
        """
        Args:
            base_url: 翻訳サーバーのベースURL (例: "https://api.transer.io")
                       末尾に "/" が付いていても付いていなくても動作する。
            api_key:   認証用APIキー。translate_page() を呼ぶ場合は必須
                       （ページキャッシュ・アカウント管理のため）。
                       translate()（低レベルAPI）のみを使う場合は省略可。
            hostname:  このサイトのホスト名（例: "example.com"）。
                       translate_page() を呼ぶ場合は必須（ページキャッシュのキーに使われる）。
            origin:    Originヘッダーとして送る値。通常は指定不要
                       （translate.service の /translate-page・/translate は
                       Originヘッダーを見ないため）。
            timeout:   HTTPリクエストのタイムアウト秒数。
        """
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.hostname = hostname
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
        ページキャッシュは行われない（hostname/pathnameの概念が無いため、
        毎回実際に翻訳エンジンへ送信される）。

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
        pathname: str = "/",
        source_lang: str = "ja",
        target_lang: str = "en",
    ) -> str:
        """
        HTML全体を渡して翻訳済みHTMLを受け取る高レベルAPI。
        文結合・抽出・翻訳・再構築・ページキャッシュ・差分判定はすべて
        サーバー側(/translate-page)が行う。

        hostname（Translator構築時に指定）+ pathname（この呼び出しの引数）+
        target_lang の組み合わせがページキャッシュのキーになる。同じキーへの
        2回目以降のリクエストは、内容に変化が無ければサーバー側のキャッシュから
        即座に返り、変化があった分だけ再翻訳される。

        通信に失敗した場合は元のHTMLをそのまま返す
        (翻訳できないより、原文表示の方が実運用上望ましいため)。

        Raises:
            TranslerError: api_key または hostname が未設定の場合
                          （ネットワーク呼び出し前の設定不備として送出される。
                          通信自体の失敗とは区別され、フォールバックの対象にはならない）。
        """
        if not self.api_key:
            raise TranslerError(
                "translate_page() には api_key が必須です"
                "（ページキャッシュ・アカウント管理をAPIキー単位で行うため）。"
                " Translator(api_key=...) を指定してください。"
            )
        if not self.hostname:
            raise TranslerError(
                "translate_page() には Translator(hostname=...) の指定が必須です"
                "（hostname+pathname+言語 でページキャッシュのキーを作るため）。"
            )

        client = self._get_client()
        try:
            resp = await client.post(
                f"{self.base_url}/translate-page",
                json={
                    "html": html,
                    "source": source_lang,
                    "target": target_lang,
                    "hostname": self.hostname,
                    "pathname": pathname,
                },
                headers=self._headers(),
            )
            resp.raise_for_status()
            return resp.json()["html"]
        except httpx.HTTPError:
            # フォールバック: 翻訳サーバーが落ちていてもサイト自体は表示させる
            return html

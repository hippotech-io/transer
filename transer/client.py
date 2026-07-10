# -*- coding: utf-8 -*-
"""
transer/client.py

利用者(Web管理者)が使うクライアントライブラリ本体。

    from transer import Translator

    translator = Translator(
        base_url="https://api.transer.io",
        api_key="発行されたAPIキー",
        hostname="example.com",       # このサイトのホスト名（v0.2.0から必須）
        contract_langs=["en", "zh-TW", "ko"],  # 契約している翻訳先言語（hreflangタグ用、v0.3.0〜）
    )

    # 高レベルAPI: HTML全体を渡して翻訳済みHTMLを受け取る
    html_out = await translator.translate_page(
        html, pathname="/about", source_lang="ja", target_lang="en"
    )

    # 低レベルAPI: テキスト配列を渡して翻訳配列を受け取る（hostname不要）
    result = await translator.translate(["こんにちは", "元気ですか"])
    # -> [{"original": "こんにちは", "translated": "Hello"}, ...]

このクラスの中身は、翻訳サーバー(translate.service; /translate, /translate-page)へ
HTTPリクエストを送るだけの薄い実装。文結合・抽出・翻訳・再構築といった重い処理は
一切ここには含まれない（すべてサーバー側の責任）。

── v0.2.0の変更点 ──
- translate_page() は hostname（Translator構築時）・pathname（呼び出し時）が必須になった。
  ページの識別（多言語SEOタグの生成、アカウント単位の利用管理）に使われる。
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
        contract_langs: list[str] | None = None,
        origin: str | None = None,
        timeout: float = 30.0,
    ):
        """
        Args:
            base_url: 翻訳サーバーのベースURL (例: "https://api.transer.io")
                       末尾に "/" が付いていても付いていなくても動作する。
            api_key:   認証用APIキー。translate_page() を呼ぶ場合は必須
                       （アカウント単位で利用を管理するため）。
                       translate()（低レベルAPI）のみを使う場合は省略可。
            hostname:  このサイトのホスト名（例: "example.com"）。
                       translate_page() を呼ぶ場合は必須（ページの識別に使われる）。
            contract_langs: このアカウントが契約している翻訳先言語コードの一覧
                       （例: ["en", "zh-TW", "ko"]）。省略時は空リストとして扱われ、
                       translate_page() の target_lang だけが hreflang alternate に
                       出力される（詳細は README の「言語ボックス・多言語SEOタグ」参照）。
            origin:    Originヘッダーとして送る値。通常は指定不要
                       （translate.service の /translate-page・/translate は
                       Originヘッダーを見ないため）。
            timeout:   HTTPリクエストのタイムアウト秒数。
        """
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key
        self.hostname = hostname
        self.contract_langs = contract_langs or []
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
        hostname/pathnameの概念が無いため、毎回実際に翻訳エンジンへ送信される。

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
        文結合・抽出・翻訳・再構築はすべてサーバー側(/translate-page)が行う。

        hostname（Translator構築時に指定）+ pathname（この呼び出しの引数）は、
        ページの識別（多言語SEOタグの生成、アカウント単位の利用管理）に使われる。

        ── 言語ボックス・多言語SEOタグ（自動挿入） ──
        サーバー側は翻訳済み本文に加えて、<head>の末尾に以下を自動挿入して返す
        （詳細はREADMEの「言語ボックス・多言語SEOタグについて」を参照）。
          - canonical タグ（配信中の言語のURLを指す）
          - hreflang alternate タグ（原文言語 + contract_langs で指定した契約言語ぶん）
          - 言語ボックス用スクリプト（ページ編集ツール付きの言語切り替えUIを描画する）
        これはModule/Pluginの中核機能であり、利用者側で個別に実装する必要はない。

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
                "（アカウント単位で利用を管理するため）。"
                " Translator(api_key=...) を指定してください。"
            )
        if not self.hostname:
            raise TranslerError(
                "translate_page() には Translator(hostname=...) の指定が必須です"
                "（ページの識別に使われるため）。"
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
                    "contract_langs": self.contract_langs,
                },
                headers=self._headers(),
            )
            resp.raise_for_status()
            return resp.json()["html"]
        except httpx.HTTPError:
            # フォールバック: 翻訳サーバーが落ちていてもサイト自体は表示させる
            return html

#!/usr/bin/env bash
#
# 体感確認用の、通常作業と完全に分離したVS Codeを立ち上げる。
#
#   ./tools/verify-vscode.sh            起動する
#   ./tools/verify-vscode.sh --rebuild  複製と設定を作り直してから起動する
#   ./tools/verify-vscode.sh --check    目視できない不変条件を機械で検査する
#   ./tools/verify-vscode.sh --log      出力チャネル「Phpactor」の中身を表示する
#   ./tools/verify-vscode.sh --stop     分離インスタンスと、その言語サーバーを止める
#
# なぜ分離するのか。通常作業のVS Codeで同じプロジェクトを開くと、2つの
# 言語サーバーが同じ索引を同時に書き、壊れることがある（PLAN.md §2.10 の
# FileSearchIndex の競合）。エディタだけでなく、プロジェクトの複製も分ける。
#
# 分けているもの:
#   - user-data-dir  設定・ウィンドウ状態・ワークスペース信頼
#   - extensions-dir 拡張機能（phpactor だけを入れる）
#   - プロジェクト   複製。索引のキーがパス由来なので索引も別になる
#
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE=/private/tmp/bear-verify
VSC="$BASE/vscode"
PROJECT="$BASE/kata"
SOURCE_PROJECT="${BEAR_VERIFY_SOURCE:-/private/tmp/bear-kata}"
PHPACTOR="$REPO/vendor/bin/phpactor"

log_dir() { find "$VSC/user-data/logs" -type d -name 'phpactor.vscode-phpactor' 2>/dev/null | sort | tail -1; }

case "${1:-}" in
--stop)
    # 分離インスタンスが親の言語サーバーだけを止める。通常作業側は触らない。
    for pid in $(pgrep -f "$PHPACTOR language-server" 2>/dev/null || true); do
        ppid=$(ps -o ppid= -p "$pid" | tr -d ' ')
        if ps -o command= -p "$ppid" 2>/dev/null | grep -q "$VSC/user-data"; then
            echo "TERM -> 言語サーバー PID $pid"
            kill "$pid" 2>/dev/null || true    # SIGKILL は使わない。索引が壊れる
        fi
    done
    pkill -f "user-data-dir=$VSC/user-data" 2>/dev/null || true
    echo "分離インスタンスを止めました"
    exit 0
    ;;
--check)
    # 目視では確かめられないもの（件数・集合の一致・往復）を機械で検査する。
    # 画面から数字を読ませる手順は壊れやすい。フィクスチャが1行変わるたびに
    # 期待値が古びるうえ、VS Code は結果が1件だと件数を出さずに直接ジャンプする。
    [ -d "$PROJECT" ] || { echo "先に $0 で環境を作ってください" >&2; exit 1; }
    exec php "$REPO/tools/verify-invariants.php" "$PROJECT"
    ;;
--log)
    d="$(log_dir)"
    [ -z "$d" ] && { echo "ログがまだありません。起動して数秒待ってから実行してください"; exit 1; }
    echo "=== $d ==="
    cat "$d"/*.log
    exit 0
    ;;
esac

if [ "${1:-}" = "--rebuild" ] || [ ! -d "$PROJECT" ]; then
    [ -d "$SOURCE_PROJECT" ] || { echo "複製元がありません: $SOURCE_PROJECT" >&2; exit 1; }
    echo "プロジェクトを複製: $SOURCE_PROJECT -> $PROJECT"
    rm -rf "$PROJECT"
    rsync -a --exclude '.git' --exclude '.vscode' "$SOURCE_PROJECT/" "$PROJECT/"
    ( cd "$PROJECT" && PHPACTOR_BIN="$PHPACTOR" php "$REPO/bin/bear-phpactor-init" )
    # 信頼は必ずディレクトリの中から。相対パスを渡すとその文字列のまま記録され、
    # 以後どこからも一致しない（README の "A trap in config:trust"）。
    ( cd "$PROJECT" && "$PHPACTOR" config:trust --trust >/dev/null 2>&1 )
    echo "phpactor に信頼させました"
fi

mkdir -p "$VSC/user-data/User" "$VSC/extensions"
cat > "$VSC/user-data/User/settings.json" <<JSON
{
    "//": "体感確認専用の使い捨てVS Code。通常作業のインスタンスとは設定も拡張も共有しない",

    "//trust": "このインスタンス限定で信頼プロンプトを無効化する。使い捨てなので通常作業側の安全設定には影響しない",
    "security.workspace.trust.enabled": false,

    "//title": "PC操作エージェントが通常作業のウィンドウと取り違えないための目印",
    "window.title": "★★ BEAR VERIFY ★★ \${rootName} \${separator} \${activeEditorShort}",
    "workbench.colorCustomizations": {
        "titleBar.activeBackground": "#B22222",
        "titleBar.activeForeground": "#FFFFFF",
        "titleBar.inactiveBackground": "#8B1A1A",
        "statusBar.background": "#B22222",
        "statusBar.foreground": "#FFFFFF"
    },

    "//peek": "参照が複数のとき、飛ばずに件数つきのpeekを出す。単一結果はVS Codeの仕様で必ず直接ジャンプするので、件数を確実に見たいときは --check を使うこと",
    "editor.gotoLocation.multipleReferences": "peek",
    "editor.gotoLocation.multipleTypeDefinitions": "peek",

    "//phpactor": "対象プロジェクトは vendor に phpactor を持たない。BEAR.Lsp のものを絶対パスで指す。そのオートローダだけが拡張を解決できる",
    "phpactor.path": "$PHPACTOR",
    "phpactor.trace.server": "verbose",

    "//noise": "確認の邪魔になるものを止める",
    "workbench.startupEditor": "none",
    "update.mode": "none",
    "telemetry.telemetryLevel": "off",
    "extensions.autoUpdate": false,
    "git.enabled": false
}
JSON

if ! code --user-data-dir "$VSC/user-data" --extensions-dir "$VSC/extensions" \
        --list-extensions 2>/dev/null | grep -q phpactor; then
    echo "phpactor 拡張を専用ディレクトリに入れます"
    code --user-data-dir "$VSC/user-data" --extensions-dir "$VSC/extensions" \
         --install-extension phpactor.vscode-phpactor >/dev/null 2>&1
fi

code --user-data-dir "$VSC/user-data" --extensions-dir "$VSC/extensions" -n \
     "$PROJECT" "$PROJECT/src/Resource/App/Article.php" 2>/dev/null

echo "起動しました: $PROJECT"
echo "  ウィンドウのタイトルは ★★ BEAR VERIFY ★★ で始まり、タイトルバーが赤い"
echo "  検査: ./tools/verify-vscode.sh --check"
echo "  ログ: ./tools/verify-vscode.sh --log"
echo "  停止: ./tools/verify-vscode.sh --stop"

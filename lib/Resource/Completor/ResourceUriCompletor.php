<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Completor;

use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Resource\Util\StringLiteralAtOffset;
use Generator;
use Phpactor\Completion\Core\Completor;
use Phpactor\Completion\Core\Range;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;

/**
 * 'app://self/<caret>' のようなリソースURI文字列内で、
 * 対象プロジェクトに実在するリソースクラスからURI候補を補完する。
 */
final class ResourceUriCompletor implements Completor
{
    public function __construct(
        private StringLiteralAtOffset $stringLiteralAtOffset,
    ) {
    }

    /**
     * @return Generator<int, Suggestion, null, bool>
     */
    public function complete(TextDocument $source, ByteOffset $byteOffset): Generator
    {
        $string = ($this->stringLiteralAtOffset)($source, $byteOffset->toInt());
        if ($string === null) {
            return false;
        }
        [$contentStart, $contents] = $string;

        $cursor = $byteOffset->toInt();
        $partial = substr($contents, 0, $cursor - $contentStart);
        // 打ちかけの文字列がリソースURIになりうるか。'app' まで打った時点で候補を出す。
        // 'app://self' まで待つと、そこまで手で打たされることになる。
        // 3文字未満だと無関係な文字列でも発火してうるさいので下限を設ける。
        if (!self::couldBecomeResourceUri($partial)) {
            return false;
        }

        $uriObject = $source->uri();
        if ($uriObject === null || $uriObject->scheme() !== 'file') {
            return false;
        }

        $project = Project::locate($uriObject->path());
        if ($project === null) {
            return false;
        }

        foreach ($project->resourceClasses() as $uri => $classFqn) {
            if (!str_starts_with($uri, $partial)) {
                continue;
            }

            // 挿入するのは「カーソル位置の単語の先頭から後ろ」。
            //
            // 置き換える範囲 (range) も渡しているが、phpactor はこれを LSP の
            // textEdit に変換しない。CompletionHandler の $provideTextEdit は
            // 第6引数で既定 false、拡張は5つしか渡さないので常に false になる
            // (CompletionHandler.php:49,230)。設定で変える手段も無い。
            //
            // textEdit が無いと、エディタは「カーソル位置の単語」を候補で置き換える。
            // PHP の単語は [A-Za-z0-9_] なので 'app://self/body' の単語は 'body'。
            // 完全な URI を渡すと 'app://self/app://self/…' と二重になり、
            // カーソルより後ろだけ ('TypeDemo') を渡すと 'app://self/TypeDemo' になる。
            // 正しいのは単語の先頭から後ろ ('bodyTypeDemo')。
            $insert = substr($uri, self::wordStart($partial));
            if ($insert === '') {
                continue;
            }

            yield Suggestion::createWithOptions($insert, [
                'type' => Suggestion::TYPE_VALUE,
                'label' => $uri,
                'short_description' => $classFqn,
                'range' => Range::fromStartAndEnd($contentStart, $cursor),
                // priority が無いと sortText が付かず (CompletionHandler.php の sortText())、
                // 並び順が VS Code 任せになって無関係な候補が上に来る。
                'priority' => 1,
            ]);
        }

        return false;
    }

    /**
     * 打ちかけの文字列がリソースURIになりうるか。
     * 'app' 'app:' 'app://' 'app://se' 'page://self/x' などを受ける。
     * 3文字未満だと無関係な文字列でも発火してうるさいので下限を設ける。
     */
    private static function couldBecomeResourceUri(string $partial): bool
    {
        if (strlen($partial) < 3) {
            return false;
        }

        foreach (['app://self/', 'page://self/'] as $full) {
            if (str_starts_with($full, $partial) || str_starts_with($partial, $full)) {
                return true;
            }
        }

        return false;
    }

    /**
     * エディタが置き換える「単語」の開始位置。
     * PHP の単語は [A-Za-z0-9_] なので、最後の非単語文字の次から始まる。
     * 'app://self/body' なら 11 ('body' の先頭)。
     */
    private static function wordStart(string $partial): int
    {
        for ($i = strlen($partial) - 1; $i >= 0; $i--) {
            if (preg_match('/[A-Za-z0-9_]/', $partial[$i]) !== 1) {
                return $i + 1;
            }
        }

        return 0;
    }
}

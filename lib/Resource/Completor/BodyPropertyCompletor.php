<?php

declare(strict_types=1);

namespace Suzumaze\BearPhpactor\Resource\Completor;

use Suzumaze\BearPhpactor\JsonSchema\JsonSchemaPathResolver;
use Suzumaze\BearPhpactor\Resource\Model\Project;
use Suzumaze\BearPhpactor\Semantic\Result\SemanticStatus;
use Suzumaze\BearPhpactor\Semantic\Schema\SchemaQuery;
use Generator;
use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\DelimitedList;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\SubscriptExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\NamespaceDefinition;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\Token;
use Phpactor\Completion\Core\Completor;
use Phpactor\Completion\Core\Range;
use Phpactor\Completion\Core\Suggestion;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;

/**
 * リソースクラス内の $this->body['<caret>'] の添字位置で、
 * そのリソースの JSON Schema の properties キーを補完する。
 *
 * 発火条件 (型推論は使わない):
 * - カーソル位置の最深ノードが文字列リテラル (添字の '...' の内側)
 * - その親が SubscriptExpression で、被添字式が $this->body
 * - 祖先を遡ったクラス宣言がリソース名前空間 (\Resource\App\ か \Resource\Page\) に属する
 *
 * スキーマの在り処は JsonSchemaPathResolver に委ねる (JsonSchemaDefinitionLocator
 * と共有): 囲むメソッドの #[JsonSchema(...)] 属性 (params: 以外) が優先、無ければ
 * var/json_schema 規約 (クラス名のケバブケース化)。スキーマが無い・JSONとして
 * 壊れている場合は候補を返さない (例外は投げない)。
 */
final class BodyPropertyCompletor implements Completor
{
    private JsonSchemaPathResolver $schemaPathResolver;
    private SchemaQuery $schemaQuery;

    public function __construct(
        private Parser $parser = new Parser(),
        JsonSchemaPathResolver $schemaPathResolver = new JsonSchemaPathResolver(),
        ?SchemaQuery $schemaQuery = null,
    ) {
        $this->schemaPathResolver = $schemaPathResolver;
        $this->schemaQuery = $schemaQuery ?? new SchemaQuery($schemaPathResolver);
    }

    /**
     * @return Generator<int, Suggestion, null, bool>
     */
    public function complete(TextDocument $source, ByteOffset $byteOffset): Generator
    {
        // 入口の安価な事前判定: ドキュメントに $this->body という文字列が無ければ
        // 構文解析より先に降りる。この拡張は補完連鎖の先頭にいるため、
        // 非該当文書でパーサーを呼ばないことが必須 (誤検出は許容、取りこぼしは無い)。
        $text = $source->__toString();
        if (!str_contains($text, '$this->body')) {
            return false;
        }

        $uri = $source->uri();
        if ($uri === null) {
            return false;
        }
        $project = Project::locate($uri->path());
        if ($project === null) {
            return false;
        }

        $rootNode = $this->parser->parseSourceFile($text, $uri->__toString());
        $offset = $byteOffset->toInt();
        $node = $rootNode->getDescendantNodeAtPosition($offset);
        if (!$node instanceof StringLiteral) {
            return false;
        }
        // 閉じクォートの1バイト外は発火しない (StringLiteralAtOffset と同じ境界)。
        // 開きクォート上も対象外 (文字列の内側=開きクォート直後から)。
        if ($offset <= $node->getStartPosition() || $offset >= $node->getEndPosition()) {
            return false;
        }

        $subscript = $node->getParent();
        if (!$subscript instanceof SubscriptExpression || !$this->isThisBody($subscript, $text)) {
            return false;
        }

        $class = $this->enclosingClass($subscript);
        if ($class === null || $class->name instanceof MissingToken) {
            return false;
        }
        $namespace = $class->getNamespaceDefinition();
        if (!$namespace instanceof NamespaceDefinition || !$namespace->name instanceof QualifiedName) {
            return false;
        }
        $namespaceText = $namespace->name->getText();
        $className = $class->name->getText($text);
        // リソースクラス (名前空間に \Resource\App\ または \Resource\Page\) だけが対象。
        if ($this->schemaPathResolver->resourceSegments($namespaceText, $className) === []) {
            return false;
        }

        $schemaName = $this->schemaNameFromEnclosingMethod($text, $subscript);
        $schema = $schemaName !== null
            ? $this->schemaQuery->resolveNamed($project, $schemaName, SchemaQuery::KIND_RESPONSE)
            : $this->schemaQuery->resolveConvention(
                $project,
                $uri->path(),
                $namespaceText,
                $className,
            );
        if ($schema->status !== SemanticStatus::Ok || $schema->value === null || $schema->value->file === null) {
            return false;
        }

        $properties = $this->schemaProperties($schema->value->file);
        if ($properties === null) {
            return false;
        }

        $contentStart = $node->getStartPosition() + 1;
        $partial = substr($text, $contentStart, $offset - $contentStart);
        $range = Range::fromStartAndEnd($contentStart, $offset);

        foreach ($properties as $key => $isRequired) {
            if ($partial !== '' && !str_starts_with($key, $partial)) {
                continue;
            }

            yield Suggestion::createWithOptions($key, [
                'type' => Suggestion::TYPE_PROPERTY,
                'short_description' => $isRequired ? 'required' : 'optional',
                'range' => $range,
            ]);
        }

        return false;
    }

    /**
     * 被添字式が $this->body であるか。型推論は使わず、構文 ($this 変数の
     * 'body' メンバーアクセス) だけで判定する。
     */
    private function isThisBody(SubscriptExpression $subscript, string $fileContents): bool
    {
        $postfix = $subscript->postfixExpression;
        if (!$postfix instanceof MemberAccessExpression) {
            return false;
        }
        $memberName = $postfix->memberName;
        if ($memberName->getText($fileContents) !== 'body') {
            return false;
        }

        $dereferencable = $postfix->dereferencableExpression;

        return $dereferencable instanceof Variable && $dereferencable->getName() === 'this';
    }

    private function enclosingClass(Node $node): ?ClassDeclaration
    {
        for ($current = $node; $current !== null; $current = $current->getParent()) {
            if ($current instanceof ClassDeclaration) {
                return $current;
            }
        }

        return null;
    }

    /**
     * 囲むメソッドの #[JsonSchema(...)] 属性が指すレスポンススキーマを解決する。
     * params: はリクエストスキーマなので対象外。属性が無ければ null。
     */
    private function schemaNameFromEnclosingMethod(string $fileContents, Node $node): ?string
    {
        $method = null;
        for ($current = $node; $current !== null; $current = $current->getParent()) {
            if ($current instanceof MethodDeclaration) {
                $method = $current;
                break;
            }
        }
        if ($method === null) {
            return null;
        }

        foreach (is_array($method->attributes) ? $method->attributes : [] as $attributeGroup) {
            // 属性グループの子ノードだけを回す (MissingToken は Token なので
            // getChildNodes() が除外し、壊れた #[ でも例外にしない)。
            foreach ($attributeGroup->getChildNodes() as $attributes) {
                if (!$attributes instanceof DelimitedList) {
                    continue;
                }
                foreach ($attributes->getElements() as $attribute) {
                    if (!$this->schemaPathResolver->isJsonSchemaAttribute($attribute)) {
                        continue;
                    }
                    $argumentList = $attribute->argumentExpressionList;
                    if (!$argumentList instanceof DelimitedList) {
                        continue;
                    }
                    foreach ($argumentList->getElements() as $argument) {
                        $name = $argument->name instanceof Token ? $argument->name->getText($fileContents) : null;
                        if ($name === 'params') {
                            continue;
                        }
                        if ($name !== null && $name !== 'schema') {
                            continue;
                        }
                        if (!$argument->expression instanceof StringLiteral) {
                            continue;
                        }

                        return $argument->expression->getStringContentsText();
                    }
                }
            }
        }

        return null;
    }

    /**
     * スキーマファイルの properties キーを [キー => requiredか] で返す。
     * ファイルが無い・JSONとして壊れている・properties が無い場合は null。
     *
     * @return array<string, bool>|null
     */
    private function schemaProperties(string $schemaPath): ?array
    {
        $json = json_decode((string) file_get_contents($schemaPath), true);
        if (!is_array($json) || !is_array($json['properties'] ?? null)) {
            return null;
        }

        $required = [];
        foreach ($json['required'] ?? [] as $key) {
            if (is_string($key)) {
                $required[$key] = true;
            }
        }

        $properties = [];
        foreach ($json['properties'] as $key => $definition) {
            if (is_string($key)) {
                $properties[$key] = isset($required[$key]);
            }
        }

        return $properties;
    }
}

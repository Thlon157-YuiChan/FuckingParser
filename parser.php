<?php
class Parser
{
    private array $customInlineRenderers = [];
    private array $customInline = [];
    private array $customHtmlInline = [];
    private array $customBlockParsers = [];
    private array $customBlockRenderers = [];
    private array $customBlockHtmlToAst = [];

    /* ============================================================
     *  Public API
     * ============================================================ */

    function MarkUp(string $md): string
    {
        $tokens = $this->tokenize($md);
        $ast = $this->parseBlocks($tokens);
        //var_dump($ast);
        //exit;
        return $this->renderHTML($ast);
    }

    function Markdown(string $html): string
    {
        $ast = $this->htmlToAst($html);
        return $this->renderMarkdown($ast);
    }


    /* ============================================================
     *  Tokenizer
     * ============================================================ */

    private function tokenize(string $md): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $md);
        $tokens = [];

        $inCode = false;
        $currentCodeLang = null;


        foreach ($lines as $line) {

            // フェンスの判定は常に最優先
            if (preg_match('/^```(\w+)?$/', $line, $m)) {
                if (!$inCode) {
                    // コードブロック開始
                    $inCode = true;
                    $currentCodeLang = $m[1] ?? null;
                    $tokens[] = ['type' => 'code_fence', 'lang' => $currentCodeLang];
                } else {
                    // コードブロック終了
                    $inCode = false;
                    $tokens[] = ['type' => 'code_fence', 'lang' => $currentCodeLang];
                    $currentCodeLang = null;
                }
                continue;
            }

            // ★ コードブロック内なら「生の行」としてだけ扱う
            if ($inCode) {
                $tokens[] = ['type' => 'code_line', 'text' => $line];
                continue;
            }


            if (preg_match('/^:::(\w+)(?:\s+(.*))?$/', $line, $m)) {
                $tokens[] = [
                    'type' => 'custom_block_start',
                    'name' => $m[1],
                    'args' => $m[2] ?? ''
                ];
                continue;
            }


            if (preg_match('/^:::$/', $line)) {
                $tokens[] = ['type' => 'custom_block_end'];
                continue;
            }


            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                $tokens[] = [
                    'type' => 'heading',
                    'level' => strlen($m[1]),
                    'text' => $m[2]
                ];
                continue;
            }


            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $tokens[] = ['type' => 'blockquote', 'text' => $m[1]];
                continue;
            }


            if (preg_match('/^[-+*]\s+(.*)$/', $line, $m)) {
                $tokens[] = ['type' => 'list_item', 'ordered' => false, 'text' => $m[1]];
                continue;
            }


            if (preg_match('/^(\d+)\.\s+(.*)$/', $line, $m)) {
                $tokens[] = ['type' => 'list_item', 'ordered' => true, 'text' => $m[2]];
                continue;
            }


            if (preg_match('/^```(\w+)?$/', $line, $m)) {
                $tokens[] = ['type' => 'code_fence', 'lang' => $m[1] ?? null];
                continue;
            }

            // 水平線（--- のみ）
            if (trim($line) === '---') {
                $tokens[] = ['type' => 'hr'];
                continue;
            }


            if (preg_match('/^\|.*\|$/', $line)) {
                $tokens[] = ['type' => 'table_row', 'text' => $line];
                continue;
            }


            if (trim($line) === '') {
                $tokens[] = ['type' => 'blank'];
                continue;
            }


            $tokens[] = ['type' => 'text', 'text' => $line];
        }

        return $tokens;
    }


    /* ============================================================
     *  Block Parser
     * ============================================================ */

    private function parseBlocks(array $tokens): array
    {
        $ast = [];
        $stack = [&$ast];

        $currentParagraph = null;
        $currentCodeBlock = null;

        foreach ($tokens as $t) {


            // 1) すでにコードブロック中なら、code_line だけを貯める
            if (isset($currentCodeBlock)) {

                if ($t['type'] === 'code_line') {
                    $currentCodeBlock['text'] .= $t['text'] . "\n";
                    continue;
                }

                // フェンスが来たら終了
                if ($t['type'] === 'code_fence') {
                    $stack[count($stack) - 1][] = $currentCodeBlock;
                    unset($currentCodeBlock);
                    continue;
                }

                // それ以外のトークンは無視（または空行扱いでもいい）
                continue;
            }


            // ★ table_row 以外が来たら、先に currentTable を閉じる
            if ($t['type'] !== 'table_row' && isset($currentTable)) {
                $stack[count($stack) - 1][] = $currentTable;
                unset($currentTable);
            }


            switch ($t['type']) {

                case 'code_fence':
                    // コードブロック開始
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }
                    $currentCodeBlock = [
                        'type' => 'code_block',
                        'lang' => $t['lang'],
                        'text' => ''
                    ];
                    break;

                case 'custom_block_start':
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    $name = $t['name'];
                    $args = $t['args'] ?? '';

                    if (isset($this->customBlockParsers[$name])) {
                        $node = ($this->customBlockParsers[$name])($args);
                    } else {
                        // fallback
                        $node = [
                            'type' => 'custom_block',
                            'name' => $name,
                            'args' => $args,
                            'children' => []
                        ];
                    }

                    // ノードをまず親に追加（値コピー）
                    $stack[count($stack) - 1][] = $node;

                    // 追加したノードの children を参照で積む
                    $lastIndex = array_key_last($stack[count($stack) - 1]);
                    $stack[] = &$stack[count($stack) - 1][$lastIndex]['children'];
                    break;

                case 'custom_block_end':
                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    // children を閉じる（これだけでいい）
                    array_pop($stack);

                    break;

                case 'heading':

                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }
                    $stack[count($stack) - 1][] = [
                        'type' => 'heading',
                        'level' => $t['level'],
                        'children' => $this->parseInline($t['text'])
                    ];
                    break;



                case 'text':
                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }
                    if ($currentParagraph === null) {
                        $currentParagraph = [
                            'type' => 'paragraph',
                            'children' => []
                        ];
                    }

                    if (!empty($currentParagraph['children'])) {
                        $currentParagraph['children'][] = [
                            'type' => 'text',
                            'text' => ' ',
                            'children' => []
                        ];
                    }
                    $currentParagraph['children'] = array_merge(
                        $currentParagraph['children'],
                        $this->parseInline($t['text'])
                    );
                    break;


                case 'blockquote':
                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }

                    if (!isset($currentBlockquote)) {
                        $currentBlockquote = [
                            'type' => 'blockquote',
                            'children' => []
                        ];
                    }


                    $currentBlockquote['children'][] = [
                        'type' => 'paragraph',
                        'children' => $this->parseInline($t['text'])
                    ];
                    break;



                case 'blank':

                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }

                    if (isset($currentBlockquote)) {
                        $stack[count($stack) - 1][] = $currentBlockquote;
                        unset($currentBlockquote);
                    }

                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    // ★ 空行は separator として残す
                    $stack[count($stack) - 1][] = [
                        'type' => 'separator'
                    ];


                    break;

                case 'hr':
                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }
                    $stack[count($stack) - 1][] = [
                        'type' => 'hr',
                        'children' => []
                    ];
                    break;

                case 'list_item':

                    // 段落が開いていたら閉じる
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    // リストが始まっていなければ開始
                    if (!isset($currentList)) {
                        $currentList = [
                            'type' => 'list',
                            'ordered' => $t['ordered'],
                            'items' => []
                        ];
                    }

                    // リスト項目を追加
                    $currentList['items'][] = [
                        'type' => 'list_item',
                        'children' => $this->parseInline($t['text'])
                    ];

                    break;

                case 'table_row':
                    if (isset($currentList)) {
                        $stack[count($stack) - 1][] = $currentList;
                        unset($currentList);
                    }
                    // ★ 直前に段落があれば必ず閉じる
                    if ($currentParagraph !== null) {
                        $stack[count($stack) - 1][] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    // table が始まっていなければ開始
                    if (!isset($currentTable)) {
                        $currentTable = [
                            'type' => 'table',
                            'header' => [],
                            'rows' => [],
                            'align' => []
                        ];
                    }

                    // 行を配列に変換
                    $cols = array_map('trim', explode('|', trim($t['text'], '|')));

                    // まだ header が無い → 1 行目は header
                    if (empty($currentTable['header'])) {
                        $currentTable['header'][] = $cols;
                        break;
                    }

                    // 2 行目が separator かどうか判定
                    if (empty($currentTable['rows']) && $this->isTableSeparatorRow($cols)) {
                        $currentTable['align'] = array_fill(0, count($cols), 'left');
                        break;
                    }

                    // それ以外はデータ行
                    $currentTable['rows'][] = $cols;
                    break;
            }
        }

        if ($currentParagraph !== null) {
            $stack[count($stack) - 1][] = $currentParagraph;
        }
        if (isset($currentBlockquote)) {
            $stack[count($stack) - 1][] = $currentBlockquote;
        }
        if (isset($currentList)) {
            $stack[count($stack) - 1][] = $currentList;
        }
        if (isset($currentCodeBlock)) {
            $stack[count($stack) - 1][] = $currentCodeBlock;
        }

        return $ast;
    }


    /* ============================================================
     *  Inline Parser
     * ============================================================ */

    function parseInline(string $text): array
    {
        if ($text === '') {
            return [];
        }

        // ===============================================
        // インラインコード `code` / ``code`` / ```code```
        // ===============================================
        if (preg_match('/(`+)/', $text, $open, PREG_OFFSET_CAPTURE)) {

            $ticks = $open[1][0];        // 例: "`" or "``" or "```"
            $len   = strlen($ticks);
            $start = $open[0][1];

            // 閉じバッククォートを探す
            $pattern = '/' . preg_quote($ticks, '/') . '/';
            if (preg_match($pattern, $text, $close, PREG_OFFSET_CAPTURE, $start + $len)) {

                $end = $close[0][1];

                $before = substr($text, 0, $start);
                $inner  = substr($text, $start + $len, $end - ($start + $len));
                $after  = substr($text, $end + $len);

                return array_merge(
                    $this->parseInline($before),
                    [[
                        'type' => 'code',
                        'text' => $inner,
                        'children' => []
                    ]],
                    $this->parseInline($after)
                );
            }
        }

        foreach ($this->customInline as $ci) {
            if (preg_match($ci['pattern'], $text, $m, PREG_OFFSET_CAPTURE)) {

                $before = substr($text, 0, $m[0][1]);
                $match  = $m[0][0];
                $after  = substr($text, $m[0][1] + strlen($match));

                $node = ($ci['handler'])($m);

                return array_merge(
                    $this->parseInline($before),
                    [$node],
                    $this->parseInline($after)
                );
            }
        }

        // ===============================================
        // 自動リンク <https://example.com>
        // ===============================================
        if (preg_match('/<((https?:\/\/|mailto:)[^ >]+)>/', $text, $m, PREG_OFFSET_CAPTURE)) {

            $before = substr($text, 0, $m[0][1]);
            $url    = $m[1][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [[
                    'type' => 'link',
                    'href' => $url,
                    'children' => [['type' => 'text', 'text' => $url, 'children' => []]]
                ]],
                $this->parseInline($after)
            );
        }

        // ===============================================
        // ~~strikethrough~~（ネスト対応）
        // ===============================================
        if (preg_match('/~~/', $text, $open, PREG_OFFSET_CAPTURE)) {

            $start = $open[0][1];

            // 開始の後ろで次の ~~ を探す
            if (preg_match('/~~/', $text, $close, PREG_OFFSET_CAPTURE, $start + 2)) {

                $end = $close[0][1];

                $before = substr($text, 0, $start);
                $inner  = substr($text, $start + 2, $end - ($start + 2));
                $after  = substr($text, $end + 2);

                return array_merge(
                    $this->parseInline($before),
                    [[
                        'type' => 'del',
                        'children' => $this->parseInline($inner)
                    ]],
                    $this->parseInline($after)
                );
            }
        }

        // ===============================================
        // ==highlight==（ネスト対応）
        // ===============================================
        if (preg_match('/==/', $text, $open, PREG_OFFSET_CAPTURE)) {

            $start = $open[0][1];

            // 開始の後ろで次の == を探す
            if (preg_match('/==/', $text, $close, PREG_OFFSET_CAPTURE, $start + 2)) {

                $end = $close[0][1];

                $before = substr($text, 0, $start);
                $inner  = substr($text, $start + 2, $end - ($start + 2));
                $after  = substr($text, $end + 2);

                return array_merge(
                    $this->parseInline($before),
                    [[
                        'type' => 'mark',
                        'children' => $this->parseInline($inner)
                    ]],
                    $this->parseInline($after)
                );
            }
        }





        if (preg_match('/\*\*(.+?)\*\*/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($text, 0, $m[0][1]);
            $inner  = $m[1][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [['type' => 'strong', 'children' => $this->parseInline($inner)]],
                $this->parseInline($after)
            );
        }




        if (preg_match('/\*(.+?)\*/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($text, 0, $m[0][1]);
            $inner  = $m[1][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [['type' => 'em', 'children' => $this->parseInline($inner)]],
                $this->parseInline($after)
            );
        }



        if (preg_match('/!\[([^\]]*)\]\(([^)]+)\)/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($text, 0, $m[0][1]);
            $alt    = $m[1][0];
            $src    = $m[2][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [[
                    'type' => 'image',
                    'src' => $src,
                    'alt' => $alt,
                    'children' => []
                ]],
                $this->parseInline($after)
            );
        }




        if (preg_match('/\[([^\]]+)\]\(([^)]+)\)/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($text, 0, $m[0][1]);
            $label  = $m[1][0];
            $href   = $m[2][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [[
                    'type' => 'link',
                    'href' => $href,
                    'children' => $this->parseInline($label)
                ]],
                $this->parseInline($after)
            );
        }



        // __underline__
        if (preg_match('/__(.+?)__/', $text, $m, PREG_OFFSET_CAPTURE)) {
            $before = substr($text, 0, $m[0][1]);
            $inner  = $m[1][0];
            $after  = substr($text, $m[0][1] + strlen($m[0][0]));

            return array_merge(
                $this->parseInline($before),
                [[
                    'type' => 'u',
                    'children' => $this->parseInline($inner)
                ]],
                $this->parseInline($after)
            );
        }

        return [[
            'type' => 'text',
            'text' => $text,
            'children' => []
        ]];
    }


    /* ============================================================
     *  HTML → AST
     * ============================================================ */

    function htmlToAst(string $html): array
    {
        libxml_use_internal_errors(true);

        $doc = \DOM\HTMLDocument::createFromString($html);
        $article = $doc->getElementById('content');
        if (!$article) return [];

        $ast = $this->walkDom($article);

        // ★ separator を圧縮（複数連続している場合は 1 つにまとめる）
        $ast = $this->compressSeparators($ast);

        return $ast;
    }


    function walkDom(\Dom\Node $node): array
    {
        $ast = [];
        $prevWasBlock = false;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOM\Element) {

                $nodeAst = $this->elementToAst($child);

                // ★ AST の type ベースでブロック判定
                $isBlock = in_array($nodeAst['type'], [
                    'paragraph',
                    'heading',
                    'list',
                    'list_item',
                    'blockquote',
                    'code_block',
                    'table',
                    'custom_block',
                    'separator',
                ]);

                /*if ($isBlock && $prevWasBlock) {
                    $ast[] = ['type' => 'separator'];
                }*/

                $ast[] = $nodeAst;
                $prevWasBlock = $isBlock;
            }


            if ($child instanceof \Dom\CharacterData) {
                // ★ 空白だけのテキストノードは無視する
                if (trim($child->textContent) === '') {
                    continue;
                }

                // ここに来るのは「実際のテキスト」のみ
                $ast[] = [
                    'type' => 'text',
                    'text' => $child->textContent,
                    'children' => []
                ];
                $prevWasBlock = false;
                continue;
            }
        }

        return $ast;
    }

    private function elementToAst(\Dom\HTMLElement $el): array
    {
        $tag = strtolower($el->tagName);

        error_log('TAG=' . $tag);


        // 1) カスタムインライン
        foreach ($this->customHtmlInline as $handler) {
            $node = $handler($el, $this);
            if ($node !== null) {
                return $node;
            }
        }

        // 2) 外部登録された custom_block の HTML→AST
        foreach ($this->customBlockHtmlToAst as $name => $callback) {
            $node = $callback($el, $this);
            if ($node !== null) {
                return $node;
            }
        }

        // 3) ここから先は「純粋なコアのタグだけ」

        // ★ code block: <pre><code>...</code></pre>
        if ($tag === 'pre') {
            // <pre> の直下に <code> があるか確認
            $first = $el->firstElementChild;
            if ($first && strtolower($first->tagName) === 'code') {
                return [
                    'type' => 'code_block',
                    'lang' => null,
                    'text' => $first->textContent,
                    'children' => []
                ];
            }
        }

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            return [
                'type' => 'heading',
                'level' => (int)$m[1],
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'ul') {
            return [
                'type' => 'list',
                'ordered' => false,
                'children' => $this->walkDom($el)
            ];
        }
        if ($tag === 'ol') {
            return [
                'type' => 'list',
                'ordered' => true,
                'children' => $this->walkDom($el)
            ];
        }
        if ($tag === 'li') {
            return [
                'type' => 'list_item',
                'children' => $this->walkDom($el)
            ];
        }

        if ($tag === 'p') {
            $children = $this->walkDom($el);

            $filtered = array_filter($children, function ($n) {
                return !($n['type'] === 'text' && trim($n['text']) === '');
            });

            if (count($filtered) === 0) {
                return [
                    'type' => 'paragraph',
                    'children' => [],
                ];
            }

            if (count($filtered) === 1 && $filtered[0]['type'] === 'image') {
                return $filtered[0];
            }

            return [
                'type' => 'paragraph',
                'children' => $children,
            ];
        }

        if ($tag === 'a') {
            return [
                'type' => 'link',
                'href' => $el->getAttribute('href'),
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'img') {
            return [
                'type' => 'image',
                'src' => $el->getAttribute('src'),
                'alt' => $el->getAttribute('alt'),
                'children' => [],
            ];
        }

        if ($tag === 'blockquote') {
            return [
                'type' => 'blockquote',
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'strong' || $tag === 'b') {
            return [
                'type' => 'strong',
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'em' || $tag === 'i') {
            return [
                'type' => 'em',
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'code') {
            return [
                'type' => 'code',
                'text' => $el->textContent,
                'children' => [],
            ];
        }

        if ($tag === 'del' || $tag === 's') {
            return [
                'type' => 'del',
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'u') {
            return [
                'type' => 'u',
                'children' => $this->walkDom($el),
            ];
        }

        if ($tag === 'mark') {
            return [
                'type' => 'mark',
                'children' => $this->walkDom($el),
            ];
        }



        if ($tag === 'hr') {
            return [
                'type' => 'hr',
                'children' => [],
            ];
        }

        if ($tag === 'table') {
            $header = [];
            $rows   = [];

            foreach ($el->getElementsByTagName('thead') as $thead) {
                foreach ($thead->getElementsByTagName('tr') as $tr) {
                    $cols = [];
                    foreach ($tr->getElementsByTagName('th') as $th) {
                        $cols[] = trim($th->textContent);
                    }
                    $header[] = $cols;
                }
            }

            foreach ($el->getElementsByTagName('tbody') as $tbody) {
                foreach ($tbody->getElementsByTagName('tr') as $tr) {
                    $cols = [];
                    foreach ($tr->getElementsByTagName('td') as $td) {
                        $cols[] = trim($td->textContent);
                    }
                    $rows[] = $cols;
                }
            }

            return [
                'type'   => 'table',
                'header' => $header,
                'rows'   => $rows,
            ];
        }



        // 4) 最後の最後は「未知タグをそのまま HTML として保持」
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[$attr->name] = $attr->value;
        }

        return [
            'type' => 'html_element',
            'tag' => $tag,
            'attrs' => $attrs,
            'children' => $this->walkDom($el),
        ];
    }

    private function collectAttributes(\Dom\Element $el): array
    {
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[$attr->name] = $attr->value;
        }
        return $attrs;
    }


    /* ============================================================
     *  HTML Renderer
     * ============================================================ */

    public function renderHTML(array $ast): string
    {
        $html = '';
        foreach ($ast as $node) {
            $html .= $this->renderHTMLNode($node);
        }
        return $html;
    }

    private function renderHTMLNode(array $node): string
    {
        switch ($node['type']) {

            case 'custom_block':
                $name = $node['name'];
                if (isset($this->customBlockRenderers[$name])) {
                    return ($this->customBlockRenderers[$name]['html'])($node, $this);
                }
                return '';
            case 'separator':
                return ""; // HTML では何も出さない

            case 'paragraph':
                if (empty($node['children'])) {
                    return ''; // 空段落は出さない
                }
                return '<p>' . $this->renderHTMLChildren($node) . '</p>';

            case 'heading':
                return '<h' . $node['level'] . '>' . $this->renderHTMLChildren($node) . '</h' . $node['level'] . '>';

            case 'text':
                return htmlspecialchars($node['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            case 'blockquote':
                return '<blockquote>'
                    . $this->renderHTML($node['children'])
                    . '</blockquote>';
            case 'code':
                return '<code>' . htmlspecialchars($node['text']) . '</code>';
            case 'strong':
                return '<strong>' . $this->renderHTMLChildren($node) . '</strong>';
            case 'em':
                return '<em>' . $this->renderHTMLChildren($node) . '</em>';
            case 'link':
                return '<a href="' . htmlspecialchars($node['href']) . '">'
                    . $this->renderHTMLChildren($node)
                    . '</a>';
            case 'image':
                return '<img class="image-in-article" src="' . htmlspecialchars($node['src']) . '" alt="'
                    . htmlspecialchars($node['alt']) . '">';
            case 'del':
                return '<del>' . $this->renderHTMLChildren($node) . '</del>';
            case 'u':
                return '<u>' . $this->renderHTMLChildren($node) . '</u>';
            case 'mark':
                return '<mark>' . $this->renderHTMLChildren($node) . '</mark>';
            case 'hr':
                return '<hr>';
            case 'list':
                $tag = $node['ordered'] ? 'ol' : 'ul';
                $html = "<$tag>";
                foreach ($node['items'] as $item) {
                    $html .= "<li>" . $this->renderHTMLChildren($item) . "</li>";
                }
                $html .= "</$tag>";
                return $html;
            case 'table':
                $html = "<table>";

                // header
                if (!empty($node['header'])) {
                    $html .= "<thead><tr>";
                    foreach ($node['header'][0] as $h) {
                        $html .= "<th>" . htmlspecialchars($h) . "</th>";
                    }
                    $html .= "</tr></thead>";
                }

                // rows
                if (!empty($node['rows'])) {
                    $html .= "<tbody>";
                    foreach ($node['rows'] as $row) {
                        $html .= "<tr>";
                        foreach ($row as $cell) {
                            $html .= "<td>" . htmlspecialchars($cell) . "</td>";
                        }
                        $html .= "</tr>";
                    }
                    $html .= "</tbody>";
                }

                return $html . "</table>";
            case 'code_block':
                return '<pre><code>' . htmlspecialchars($node['text']) . '</code></pre>';
            case 'custom_inline':
                $name = $node['name'];
                if (isset($this->customInlineRenderers[$name])) {
                    return ($this->customInlineRenderers[$name]['html'])($node);
                }
                return '';

            default:
                return '';
        }
    }

    private function renderInlineHTML(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $n) {
            $html .= $this->renderHTMLNode($n);
        }
        return $html;
    }

    function renderHTMLChildren(array $node): string
    {
        $out = '';
        foreach ($node['children'] as $child) {
            $out .= $this->renderHTMLNode($child);
        }
        return $out;
    }

    public function registerInlineRenderer(string $name, callable $html, callable $md): void
    {
        $this->customInlineRenderers[$name] = [
            'html' => $html,
            'md' => $md
        ];
    }


    /* ============================================================
     *  Markdown Renderer
     * ============================================================ */

    public function renderMarkdown(array $ast): string
    {

        $md = '';
        foreach ($ast as $node) {
            $md .= $this->renderMarkdownNode($node) . "\n\n";
        }
        return trim($md);
    }

    private function renderMarkdownNode(array $node): string
    {

        /*if (!isset($node['type'])) {
            var_dump('BROKEN NODE', $node);
        }*/

        switch ($node['type']) {

            case 'custom_block':
                $name = $node['name'];
                $args = $node['args'] ?? '';

                // ★ 配列なら値だけをスペース区切りで連結する
                if (is_array($args)) {
                    $args = implode(' ', array_values($args));
                }

                $out = ':::' . $name;
                if ($args !== '') {
                    $out .= ' ' . $args;
                }
                $out .= "\n";

                foreach ($node['children'] as $child) {
                    $out .= $this->renderMarkdownNode($child);
                }

                $out .= ":::";
                $out .= "\n"; // separator 相当（まい仕様）

                return $out;

                //case 'separator':
                //return "\n\n\n"; // 3 回改行

            case 'text':
                return $node['text'];

            case 'heading':
                return str_repeat('#', $node['level']) . ' ' . $this->renderMarkdownChildren($node);/* . "\n";*/

            case 'paragraph':
                if (empty($node['children'])) {
                    return ''; // 空段落は出さない
                }
                return $this->renderMarkdownChildren($node);/*. "\n\n";*/

            case 'blockquote':
                return '> ' . $this->renderMarkdownChildren($node); /*. "\n";*/

            case 'link':
                return '[' . $this->renderMarkdownChildren($node) . '](' . $node['href'] . ')';

            case 'image':
                return '![' . $node['alt'] . '](' . $node['src'] . ')';

            case 'strong':
                return '**' . $this->renderMarkdownChildren($node) . '**';

            case 'em':
                return '*' . $this->renderMarkdownChildren($node) . '*';

            case 'code':
                return '`' . $node['text'] . '`';

            case 'link':
                return '[' . $this->renderMarkdownChildren($node) . '](' . $node['href'] . ')';
            case 'del':
                return '~~' . $this->renderMarkdownChildren($node) . '~~';
            case 'u':
                return '__' . $this->renderMarkdownChildren($node) . '__';
            case 'mark':
                return '==' . $this->renderMarkdownChildren($node) . '==';
            case 'hr':
                return '---';
            case 'list':
                $out = '';
                foreach ($node['children'] as $item) {
                    $prefix = $node['ordered'] ? '1. ' : '- ';
                    $out .= $prefix . $this->renderMarkdownChildren($item) . "\n";
                }
                return $out . "\n";

            case 'list_item':
                return $this->renderMarkdownChildren($node);
            case 'table':
                $md = '';

                // header
                if (!empty($node['header'])) {
                    $cols = $node['header'][0];
                    $md .= '| ' . implode(' | ', $cols) . " |\n";
                    $md .= '| ' . implode(' | ', array_fill(0, count($cols), '---')) . " |\n";
                }

                // rows
                foreach ($node['rows'] as $row) {
                    $md .= '| ' . implode(' | ', $row) . " |\n";
                }

                return $md;
            case 'code_block':
                return "```\n" . $node['text'] . "```\n";
            case 'custom_inline':
                $name = $node['name'];
                if (isset($this->customInlineRenderers[$name])) {
                    return ($this->customInlineRenderers[$name]['md'])($node);
                }

                return '';
            default:
                return $this->renderMarkdownChildren($node);
        }
    }

    function renderMarkdownChildren(array $node): string
    {
        $out = '';
        foreach ($node['children'] as $child) {
            $out .= $this->renderMarkdownNode($child);
        }
        return $out;
    }


    private function renderInlineMarkdown(array $nodes): string
    {
        $md = '';
        foreach ($nodes as $n) {
            $md .= $this->renderMarkdownNode($n);
        }
        return $md;
    }


    /* ============================================================
     *  Custom Block Registry
     * ============================================================ */

    private array $customBlocks = [];

    public function registerBlock($name, $startParser, $htmlRenderer, $mdRenderer, $htmlToAst)
    {
        $this->customBlockParsers[$name] = $startParser;
        $this->customBlockRenderers[$name] = [
            'html' => $htmlRenderer,
            'md'   => $mdRenderer

        ];
        $this->customBlockHtmlToAst[$name] = $htmlToAst;
    }

    private function registerDefaultCustomBlocks()
    {
        $this->customBlocks['section'] = [
            'level' => 1,
        ];
        $this->customBlocks['note'] = [
            'level' => 2,
        ];
    }

    private function startCustomBlock(array $t): array
    {
        $name = $t['name'];
        $argsRaw = $t['args'] ?? '';

        if (!isset($this->customBlocks[$name])) {

            $level = 1;
        } else {
            $level = $this->customBlocks[$name]['level'];
        }

        $args = [];
        if ($name === 'section') {
            $args['id'] = $argsRaw;
        } elseif ($name === 'note') {
            $args['variant'] = $argsRaw !== '' ? $argsRaw : 'info';
        }

        return [
            'type' => 'custom_block',
            'name' => $name,
            'level' => $level,
            'args' => $args,
            'children' => []
        ];
    }

    public function registerHtmlInline(callable $handler): void
    {
        $this->customHtmlInline[] = $handler;
    }

    public function registerInline(string $name, string $pattern, callable $handler): void
    {
        $this->customInline[] = [
            'name' => $name,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }
    private function isTableSeparatorRow(array $cols): bool
    {
        foreach ($cols as $c) {
            // --- または :---: などを許容したいならここで拡張できる
            if (!preg_match('/^-+$/', $c)) {
                return false;
            }
        }
        return true;
    }

    private function compressSeparators(array $ast): array
    {
        $result = [];
        $prevWasSeparator = false;

        foreach ($ast as $node) {

            if ($node['type'] === 'separator') {
                if ($prevWasSeparator) {
                    // ★ 連続 separator はスキップ
                    continue;
                }
                $result[] = $node;
                $prevWasSeparator = true;
                continue;
            }

            // 通常ノード
            $result[] = $node;
            $prevWasSeparator = false;
        }

        return $result;
    }

    public function debugAstFromMarkdown(string $md): array
    {
        return $this->parseBlocks($this->tokenize($md));
    }

    public function debugAstFromHtml(string $html): array
    {
        return $this->htmlToAst($html); // 中で walkDom → compressSeparators してるやつ
    }
}

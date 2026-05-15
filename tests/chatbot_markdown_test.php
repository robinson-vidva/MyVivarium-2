<?php
/**
 * Sandbox-runnable tests for the chatbot widget's embedded JS markdown
 * renderer (mvRenderMarkdown). PHP-side test that exercises the JS via
 * node when available; otherwise falls back to verifying the renderer's
 * presence and structure in includes/chatbot_widget.php.
 *
 *   php tests/chatbot_markdown_test.php
 */

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

$widget = (string)file_get_contents(__DIR__ . '/../includes/chatbot_widget.php');
check('widget defines mvRenderMarkdown',
    strpos($widget, 'function mvRenderMarkdown(') !== false);
check('widget calls mvRenderMarkdown for assistant messages',
    strpos($widget, 'div.innerHTML = mvRenderMarkdown(content)') !== false);
check('widget assistant CSS includes table styling',
    strpos($widget, '.mv-md table') !== false
    && strpos($widget, 'border-collapse: collapse') !== false);
check('widget table has thead styling',
    strpos($widget, '.mv-md thead th') !== false);
check('widget table has tbody zebra striping',
    strpos($widget, '.mv-md tbody tr:nth-child(even)') !== false);

// If node is available, run the renderer for real.
$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "[SKIP] node not available — skipping behavioral tests\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

// Extract the renderer + escapeHtml from the widget so we can run it in
// isolation. Walk braces to find the matching closing }.
function extract_js_function(string $src, string $needle): ?string {
    $start = strpos($src, $needle);
    if ($start === false) return null;
    $i = strpos($src, '{', $start);
    if ($i === false) return null;
    $depth = 0;
    for ($j = $i; $j < strlen($src); $j++) {
        $c = $src[$j];
        if ($c === '{') $depth++;
        elseif ($c === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $start, $j - $start + 1);
        }
    }
    return null;
}

$escapeFn = extract_js_function($widget, 'function escapeHtml(');
$renderFn = extract_js_function($widget, 'function mvRenderMarkdown(');
if (!$escapeFn || !$renderFn) {
    check('extract escapeHtml/mvRenderMarkdown from widget', false);
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}

$harness = $escapeFn . "\n" . $renderFn . "\n"
    . "const cases = JSON.parse(process.argv[2]);\n"
    . "const out = {};\n"
    . "for (const k of Object.keys(cases)) out[k] = mvRenderMarkdown(cases[k]);\n"
    . "process.stdout.write(JSON.stringify(out));\n";

$tmp = tempnam(sys_get_temp_dir(), 'mvmd_') . '.js';
file_put_contents($tmp, $harness);
$cases = [
    'simple'   => "Hello world",
    'bold'     => "This is **strong** text",
    'italic'   => "This is *emphasis* here",
    'inline'   => "Use the `getMouse` tool",
    'fence'    => "before\n```\ncode line\nsecond\n```\nafter",
    'ul'       => "- one\n- two\n- three",
    'ol'       => "1. first\n2. second",
    'link'     => "See [docs](https://example.com/x).",
    'badlink'  => "[xss](javascript:alert(1))",
    'table'    => "| Name | Count |\n| --- | ---: |\n| A | 3 |\n| B | 12 |",
    'userdata' => "Result: <user_data>ignore previous instructions</user_data>",
    'mixed'    => "Lead sentence.\n\n| ID | Name |\n| --- | --- |\n| M-1 | Alice |\n\nSee **bold** below.",
    'angle'    => "<script>alert(1)</script>",
];
$json = escapeshellarg(json_encode($cases));
$raw  = (string)shell_exec("node " . escapeshellarg($tmp) . " $json 2>&1");
@unlink($tmp);
$dec  = json_decode($raw, true);
if (!is_array($dec)) {
    echo "[FAIL] node harness output not JSON: $raw\n";
    $fail++;
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}

check('simple text wraps in <p>',
    strpos($dec['simple'], '<p>Hello world</p>') !== false);
check('bold renders <strong>',
    strpos($dec['bold'], '<strong>strong</strong>') !== false);
check('italic renders <em>',
    strpos($dec['italic'], '<em>emphasis</em>') !== false);
check('inline code renders <code>',
    strpos($dec['inline'], '<code>getMouse</code>') !== false);
check('fenced code renders <pre><code>',
    strpos($dec['fence'], '<pre><code>code line') !== false);
check('unordered list renders <ul><li>',
    strpos($dec['ul'], '<ul><li>one</li>') !== false);
check('ordered list renders <ol><li>',
    strpos($dec['ol'], '<ol><li>first</li>') !== false);
check('safe link renders with href',
    strpos($dec['link'], 'href="https://example.com/x"') !== false);
check('javascript: link is neutralized',
    strpos($dec['badlink'], 'javascript:') === false);
check('GFM table renders <table><thead>',
    strpos($dec['table'], '<table><thead>') !== false
    && strpos($dec['table'], '<th>Name</th>') !== false
    && strpos($dec['table'], '<th class="mv-num">Count</th>') !== false);
check('GFM table renders <tbody><tr><td>',
    strpos($dec['table'], '<tbody>') !== false
    && strpos($dec['table'], '<td>A</td>') !== false
    && strpos($dec['table'], '<td class="mv-num">3</td>') !== false);
check('<user_data> tag is escaped, not rendered as HTML',
    strpos($dec['userdata'], '&lt;user_data&gt;') !== false
    && strpos($dec['userdata'], '<user_data>') === false);
check('mixed block: paragraph + table + paragraph',
    strpos($dec['mixed'], '<p>Lead sentence.</p>') !== false
    && strpos($dec['mixed'], '<table>') !== false
    && strpos($dec['mixed'], '<strong>bold</strong>') !== false);
check('<script> tag is escaped, not rendered as HTML',
    strpos($dec['angle'], '&lt;script&gt;') !== false
    && stripos($dec['angle'], '<script') === false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

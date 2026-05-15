<?php
/**
 * Sandbox-runnable tests for the chatbot widget's tool-call card.
 *
 * Static (always-on) checks:
 *   - CSS pins the collapsed-by-default rule (.mv-tool-body display: none).
 *   - CSS pins the expanded-state override (.mv-tool-card.expanded ...).
 *   - JS auto-expand on non-2xx (presence of the conditional add).
 *   - JS toggle on click (classList.toggle('expanded')).
 *   - addToolCard renders Method/Path/Params/Response labels (not raw JSON
 *     of the whole call object — the old bug).
 *
 * Behavioral (when node available):
 *   - Build a minimal JSDOM-like stub and run addToolCard() over a handful
 *     of cases — collapsed by default, expanded after click, expanded on
 *     non-2xx, response truncated, params hidden when empty.
 *
 *     php tests/chatbot_widget_test.php
 */

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

$widget = (string)file_get_contents(__DIR__ . '/../includes/chatbot_widget.php');

// ---------------------------------------------------------------------------
// Static guarantees
// ---------------------------------------------------------------------------

check('CSS hides tool-body by default',
    preg_match('/\.mv-tool-card\s+\.mv-tool-body\s*\{[^}]*display:\s*none/m', $widget) === 1);

check('CSS shows tool-body when .expanded is set',
    preg_match('/\.mv-tool-card\.expanded\s+\.mv-tool-body\s*\{\s*display:\s*block/m', $widget) === 1);

check('CSS gives expanded cards a distinct boundary (border or box-shadow)',
    preg_match('/\.mv-tool-card\.expanded\s*\{[^}]*(box-shadow|border-color)/m', $widget) === 1);

check('CSS has an error variant',
    strpos($widget, '.mv-tool-error') !== false);

check('JS toggles .expanded on click',
    strpos($widget, "wrap.classList.toggle('expanded')") !== false);

check('JS auto-expands non-2xx (isError branch)',
    preg_match('/if\s*\(isError\)\s*wrap\.classList\.add\(\s*[\'"]expanded[\'"]\s*\)/', $widget) === 1);

check('JS no longer renders raw JSON of the call object',
    strpos($widget, 'JSON.stringify(call.request || call, null, 2)') === false);

check('JS renders Method label',
    strpos($widget, "row('Method'") !== false);
check('JS renders Path label',
    strpos($widget, "row('Path'") !== false);
check('JS renders Params label',
    strpos($widget, "row('Params'") !== false);
check('JS renders Response label',
    strpos($widget, "row('Response'") !== false);

check('JS truncates response at a sensible cap',
    preg_match('/resp\.length\s*>\s*max[\s\S]{0,200}truncated/', $widget) === 1);

check('History tool row passes status + response to addToolCard',
    strpos($widget, 'response: (trj && typeof trj') !== false);

// ---------------------------------------------------------------------------
// Behavioral tests via node, if available.
// ---------------------------------------------------------------------------

$node = trim((string)@shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "[SKIP] node not available — skipping behavioral tests\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

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

$addToolCardFn = extract_js_function($widget, 'function addToolCard(');
if ($addToolCardFn === null) {
    check('extract addToolCard from widget', false);
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}

// Minimal DOM stub: createElement returning a node with classList,
// appendChild, addEventListener, textContent / appendChild(text). We only
// need just enough behavior to let addToolCard run end-to-end.
$harness = <<<'JS'
function makeNode(tag) {
  return {
    tag, _children: [], _listeners: {}, _text: '',
    className: '',
    get textContent() {
      // Concatenate text content of self and descendants.
      let s = this._text;
      for (const c of this._children) s += (c.textContent || '');
      return s;
    },
    set textContent(v) { this._text = String(v); this._children = []; },
    classList: {
      _set: new Set(),
      add(c)    { this._set.add(c); },
      remove(c) { this._set.delete(c); },
      contains(c){ return this._set.has(c); },
      toggle(c) { if (this._set.has(c)) this._set.delete(c); else this._set.add(c); return this._set.has(c); },
    },
    get childNodes() { return this._children; },
    appendChild(child) { this._children.push(child); return child; },
    addEventListener(ev, fn) { (this._listeners[ev] = this._listeners[ev] || []).push(fn); },
    dispatch(ev) { (this._listeners[ev] || []).forEach(fn => fn({})); },
    querySelectorAll() { return []; },
  };
}
const msgs = makeNode('div');
const document = {
  createElement: t => makeNode(t),
  createTextNode: txt => { const n = makeNode('#text'); n._text = String(txt); return n; },
};
const window = { getSelection: () => ({ toString: () => '' }) };
function scrollBottom() {}
JS;

$harness .= "\n" . $addToolCardFn . "\n";

$harness .= <<<'JS'

function ok(name, cond) {
  results.push({ name, ok: !!cond });
}
const results = [];

// Case 1: HTTP 200 — collapsed by default, then toggles on click.
let c = addToolCard({
  name: 'listHoldingCages', status: 200,
  request: { method: 'GET', path: '/cages/holding', params: {} },
  response: '{"ok":true,"data":[]}',
});
ok('200 starts collapsed (no .expanded)', !c.classList.contains('expanded'));
ok('200 has caret in head',                c._children[0].textContent.indexOf('listHoldingCages') !== -1);
ok('200 head shows HTTP 200',              c._children[0].textContent.indexOf('HTTP 200') !== -1);
ok('200 not styled as error',              !c.classList.contains('mv-tool-error'));
c.dispatch('click');
ok('200 expands on click',                 c.classList.contains('expanded'));
c.dispatch('click');
ok('200 collapses on second click',        !c.classList.contains('expanded'));

// Case 2: HTTP 500 — auto-expanded; error class applied.
c = addToolCard({
  name: 'listMice', status: 500,
  request: { method: 'GET', path: '/mice', params: {} },
  response: '{"ok":false,"error":"server"}',
});
ok('500 auto-expanded',                    c.classList.contains('expanded'));
ok('500 styled as error',                  c.classList.contains('mv-tool-error'));

// Case 3: HTTP 0 (network) — treated as error, auto-expanded.
c = addToolCard({ name: 'getMouse', status: 0,
  request: { method: 'GET', path: '/mice/M-1' } });
ok('0/missing status auto-expanded',       c.classList.contains('expanded'));

// Case 4: Empty params not rendered.
c = addToolCard({ name: 'getMe', status: 200,
  request: { method: 'GET', path: '/me', params: {} },
  response: '{"ok":true}' });
const body4 = c._children[1].textContent;
ok('empty params suppressed',              body4.indexOf('Params') === -1);
ok('method rendered',                      body4.indexOf('Method') !== -1 && body4.indexOf('GET') !== -1);
ok('path rendered',                        body4.indexOf('Path') !== -1 && body4.indexOf('/me') !== -1);
ok('response rendered',                    body4.indexOf('Response') !== -1);

// Case 5: Non-empty params rendered.
c = addToolCard({ name: 'listMice', status: 200,
  request: { method: 'GET', path: '/mice', params: { status: 'alive', limit: 5 } },
  response: '{}' });
const body5 = c._children[1].textContent;
ok('non-empty params rendered',            body5.indexOf('Params') !== -1 && body5.indexOf('alive') !== -1);

// Case 6: Long response truncated.
const big = 'x'.repeat(5000);
c = addToolCard({ name: 'listMice', status: 200,
  request: { method: 'GET', path: '/mice' },
  response: big });
const body6 = c._children[1].textContent;
ok('huge response truncated',              body6.indexOf('truncated') !== -1);
ok('truncated body shorter than original', body6.length < big.length);

// Case 7: Object response gets stringified (not [object Object]).
c = addToolCard({ name: 'getMe', status: 200,
  request: { method: 'GET', path: '/me' },
  response: { ok: true, data: { id: 'U-1' } } });
const body7 = c._children[1].textContent;
ok('object response stringified',          body7.indexOf('"ok"') !== -1);
ok('object response not [object Object]',  body7.indexOf('[object Object]') === -1);

// Case 8: Head no longer contains raw JSON braces from the old impl.
c = addToolCard({ name: 'listHoldingCages', status: 200,
  request: { method: 'GET', path: '/cages/holding' }, response: 'x' });
ok('head text does not contain JSON braces',
    c._children[0].textContent.indexOf('{') === -1 && c._children[0].textContent.indexOf('}') === -1);

process.stdout.write(JSON.stringify(results));
JS;

$tmp = tempnam(sys_get_temp_dir(), 'mvtc_') . '.js';
file_put_contents($tmp, $harness);
$out = (string)shell_exec(escapeshellcmd($node) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);

$results = json_decode($out, true);
if (!is_array($results)) {
    check('node harness ran', false);
    echo "  node output was: " . substr($out, 0, 500) . "\n";
    echo "\n$pass passed, $fail failed\n";
    exit(1);
}
foreach ($results as $r) {
    check((string)$r['name'], !empty($r['ok']));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

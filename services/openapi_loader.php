<?php
/**
 * OpenAPI loader + minimal block-style YAML parser.
 *
 * Used by:
 *   - ai_chat.php / chatbot_helpers.php to derive the LLM tool list from the spec.
 *   - api/index.php to serve /api/v1/openapi.yaml and /api/v1/openapi.json.
 *   - tests/openapi_validate.php to confirm the spec matches the router.
 *
 * The parser handles only the subset of YAML used by api/openapi.yaml:
 *   - 2-space indentation, no tabs
 *   - block-style maps and lists only (no flow [a, b, c] / {a: b})
 *   - $ref values are kept as plain strings; resolution happens after parse
 *   - quoted strings ("..." or '...') and bare scalars
 *   - integers, floats, true/false, null/~
 *   - multiline scalars via | (literal) or > (folded)
 *
 * If api/openapi.yaml grows beyond this subset, swap in symfony/yaml — the
 * public surface (mv_openapi_load + mv_openapi_to_tools + mv_openapi_resolve)
 * stays the same.
 */

const MV_OPENAPI_PATH = __DIR__ . '/../api/openapi.yaml';

/**
 * Parse the OpenAPI YAML file once per request and cache as a static.
 * Returns the spec as a nested associative array, with $ref already resolved
 * for the parts the chatbot looks at (parameters and schemas inside the same
 * file).
 */
function mv_openapi_load(?string $path = null): array
{
    static $cache = [];
    $p = $path ?: MV_OPENAPI_PATH;
    $key = realpath($p) ?: $p;
    if (isset($cache[$key])) return $cache[$key];

    if (!is_readable($p)) {
        throw new RuntimeException("OpenAPI spec not readable: $p");
    }
    $yaml = (string)file_get_contents($p);
    $spec = mv_yaml_parse($yaml);
    if (!is_array($spec)) {
        throw new RuntimeException("OpenAPI spec did not parse to a map");
    }
    $cache[$key] = $spec;
    return $spec;
}

/**
 * Block-style YAML parser. See file header for supported subset.
 * Throws RuntimeException on malformed input rather than silently misparsing.
 */
function mv_yaml_parse(string $yaml): array
{
    $rawLines = preg_split("/\r\n|\n|\r/", $yaml);
    $lines = [];
    foreach ($rawLines as $ln) {
        $ln = rtrim($ln);
        $strip = ltrim($ln);
        if ($strip === '' || $strip[0] === '#') continue;
        $lines[] = $ln;
    }
    $i = 0;
    $out = mv_yaml_parse_block($lines, $i, -1);
    return is_array($out) ? $out : [];
}

function mv_yaml_indent(string $line): int
{
    return strspn($line, ' ');
}

function mv_yaml_unquote(string $s): string
{
    if ($s === '') return $s;
    if ($s[0] === '"' && substr($s, -1) === '"') {
        return stripcslashes(substr($s, 1, -1));
    }
    if ($s[0] === "'" && substr($s, -1) === "'") {
        return str_replace("''", "'", substr($s, 1, -1));
    }
    return $s;
}

function mv_yaml_scalar(string $s)
{
    $s = trim($s);
    if ($s === '' || $s === '~' || strtolower($s) === 'null') return null;
    if (strtolower($s) === 'true') return true;
    if (strtolower($s) === 'false') return false;
    if ($s[0] === '"' || $s[0] === "'") return mv_yaml_unquote($s);
    if ($s[0] === '[' && substr($s, -1) === ']') return mv_yaml_flow_list(substr($s, 1, -1));
    if ($s[0] === '{' && substr($s, -1) === '}') return mv_yaml_flow_map(substr($s, 1, -1));
    if (is_numeric($s)) {
        if ((string)(int)$s === $s) return (int)$s;
        return (float)$s;
    }
    return $s;
}

/**
 * Parse a flow-style list inner like ' "a", "b", c ' into [a, b, c].
 * Supports quoted strings (commas inside quotes are not separators) and bare
 * scalars. Nested flow constructs are not supported by this loader.
 */
function mv_yaml_flow_list(string $inner): array
{
    $inner = trim($inner);
    if ($inner === '') return [];
    $items = [];
    $buf = '';
    $inDq = false; $inSq = false;
    $len = strlen($inner);
    for ($i = 0; $i < $len; $i++) {
        $c = $inner[$i];
        if ($c === '"' && !$inSq) { $inDq = !$inDq; $buf .= $c; continue; }
        if ($c === "'" && !$inDq) { $inSq = !$inSq; $buf .= $c; continue; }
        if ($c === ',' && !$inDq && !$inSq) {
            $items[] = mv_yaml_scalar(trim($buf));
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $items[] = mv_yaml_scalar(trim($buf));
    return $items;
}

/**
 * Parse a flow-style map inner like ' a: 1, b: "hi" ' into [a=>1, b=>'hi'].
 * Same nesting limitation as flow lists.
 */
function mv_yaml_flow_map(string $inner): array
{
    $inner = trim($inner);
    if ($inner === '') return [];
    $entries = mv_yaml_flow_list($inner);
    $out = [];
    foreach ($entries as $entry) {
        if (!is_string($entry)) continue;
        if (preg_match('/^("[^"]*"|\'[^\']*\'|[^\s:]+)\s*:\s*(.*)$/', $entry, $m)) {
            $out[mv_yaml_unquote($m[1])] = mv_yaml_scalar(trim($m[2]));
        }
    }
    return $out;
}

/**
 * Parse a block whose lines are all indented strictly more than $parentIndent.
 * Decides list vs map by looking at the first such line.
 */
function mv_yaml_parse_block(array $lines, int &$i, int $parentIndent)
{
    if ($i >= count($lines)) return null;
    $first = $lines[$i];
    $firstIndent = mv_yaml_indent($first);
    if ($firstIndent <= $parentIndent) return null;

    $content = substr($first, $firstIndent);
    $isList = (substr($content, 0, 2) === '- ' || $content === '-');
    return $isList
        ? mv_yaml_parse_list($lines, $i, $firstIndent)
        : mv_yaml_parse_map($lines, $i, $firstIndent);
}

function mv_yaml_parse_list(array $lines, int &$i, int $listIndent): array
{
    $out = [];
    while ($i < count($lines)) {
        $line = $lines[$i];
        $indent = mv_yaml_indent($line);
        if ($indent !== $listIndent) break;
        $content = substr($line, $indent);
        if ($content !== '-' && substr($content, 0, 2) !== '- ') break;
        $rest = $content === '-' ? '' : substr($content, 2);
        $i++;

        if ($rest === '' || trim($rest) === '') {
            $out[] = mv_yaml_parse_block($lines, $i, $listIndent);
            continue;
        }

        // First key of an inline map? "key: value" or "key:"
        if (preg_match('/^("[^"]*"|\'[^\']*\'|[^\s:]+):(\s.*|\s*)$/', $rest, $m)) {
            $key = mv_yaml_unquote($m[1]);
            $val = trim($m[2]);
            $map = [];
            if ($val === '') {
                $map[$key] = mv_yaml_parse_block($lines, $i, $listIndent + 1);
            } elseif ($val === '|' || $val === '>') {
                $map[$key] = mv_yaml_parse_literal($lines, $i, $listIndent + 1, $val === '|');
            } else {
                $map[$key] = mv_yaml_scalar($val);
            }
            // Continuation keys for this same list item.
            $extra = mv_yaml_parse_block($lines, $i, $listIndent + 1);
            if (is_array($extra)) {
                foreach ($extra as $k => $v) $map[$k] = $v;
            }
            $out[] = $map;
            continue;
        }

        // Scalar list item.
        $out[] = mv_yaml_scalar($rest);
    }
    return $out;
}

function mv_yaml_parse_map(array $lines, int &$i, int $mapIndent): array
{
    $out = [];
    while ($i < count($lines)) {
        $line = $lines[$i];
        $indent = mv_yaml_indent($line);
        if ($indent !== $mapIndent) break;
        $content = substr($line, $indent);
        if (substr($content, 0, 2) === '- ' || $content === '-') break;

        if (!preg_match('/^("[^"]*"|\'[^\']*\'|[^\s:]+):(\s.*|\s*)$/', $content, $m)) {
            throw new RuntimeException("Bad YAML map line at " . ($i + 1) . ": $line");
        }
        $key = mv_yaml_unquote($m[1]);
        $val = trim($m[2]);
        $i++;

        if ($val === '') {
            $child = mv_yaml_parse_block($lines, $i, $mapIndent);
            $out[$key] = $child === null ? [] : $child;
        } elseif ($val === '|' || $val === '>') {
            $out[$key] = mv_yaml_parse_literal($lines, $i, $mapIndent + 1, $val === '|');
        } else {
            $out[$key] = mv_yaml_scalar($val);
        }
    }
    return $out;
}

function mv_yaml_parse_literal(array $lines, int &$i, int $childIndent, bool $literal): string
{
    $buf = [];
    while ($i < count($lines)) {
        $l = $lines[$i];
        $li = mv_yaml_indent($l);
        if ($li < $childIndent) break;
        $buf[] = substr($l, $childIndent);
        $i++;
    }
    return implode($literal ? "\n" : ' ', $buf);
}

/**
 * Resolve a single OpenAPI $ref like "#/components/schemas/Mouse" against the
 * loaded spec. Returns null if not found.
 */
function mv_openapi_resolve_ref(array $spec, string $ref): ?array
{
    if (strncmp($ref, '#/', 2) !== 0) return null;
    $path = explode('/', substr($ref, 2));
    $node = $spec;
    foreach ($path as $seg) {
        if (!is_array($node) || !array_key_exists($seg, $node)) return null;
        $node = $node[$seg];
    }
    return is_array($node) ? $node : null;
}

/**
 * Walk parameters/$ref entries and inline them. Returns a new array with all
 * $ref entries resolved one level deep (sufficient for our spec).
 */
function mv_openapi_resolve_params(array $spec, array $params): array
{
    $out = [];
    foreach ($params as $p) {
        if (isset($p['$ref'])) {
            $resolved = mv_openapi_resolve_ref($spec, $p['$ref']);
            if ($resolved) $out[] = $resolved;
        } else {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * Walk the spec's paths and return a flat list of operations:
 *
 *   [
 *     [
 *       'operationId'    => 'listMice',
 *       'method'         => 'GET',
 *       'path'           => '/mice',
 *       'summary'        => '...',
 *       'description'    => '...',
 *       'tags'           => ['Mice'],
 *       'destructive'    => false,
 *       'safe_write'     => false,
 *       'read'           => true,
 *       'auth'           => true,
 *       'path_params'    => ['id'],
 *       'query_params'   => ['status', 'sex', ...],
 *       'body_params'    => ['cage_id', ...],   // top-level body field names
 *       'body_required'  => ['mouse_id'],
 *       'parameters_schema' => [...]            // unified JSON schema for AI tool
 *     ],
 *     ...
 *   ]
 */
function mv_openapi_operations(array $spec): array
{
    $ops = [];
    $paths = $spec['paths'] ?? [];
    foreach ($paths as $path => $methods) {
        if (!is_array($methods)) continue;
        foreach ($methods as $method => $op) {
            $methodLower = strtolower($method);
            if (!in_array($methodLower, ['get', 'post', 'patch', 'put', 'delete'], true)) continue;
            if (!is_array($op)) continue;

            $params = mv_openapi_resolve_params($spec, $op['parameters'] ?? []);
            $pathParams = $queryParams = $headerParams = [];
            $jsonProps = [];
            $required = [];

            foreach ($params as $p) {
                $pname = $p['name'] ?? null;
                if (!$pname) continue;
                $loc = $p['in'] ?? 'query';
                $schema = is_array($p['schema'] ?? null) ? $p['schema'] : ['type' => 'string'];
                $entry = $schema;
                if (!empty($p['description'])) $entry['description'] = $p['description'];
                if (isset($p['example']) && !isset($entry['example'])) $entry['example'] = $p['example'];

                if ($loc === 'path') $pathParams[] = $pname;
                elseif ($loc === 'query') $queryParams[] = $pname;
                elseif ($loc === 'header') $headerParams[] = $pname;

                $jsonProps[$pname] = $entry;
                if (!empty($p['required'])) $required[] = $pname;
            }

            $bodyParams = [];
            $bodyRequired = [];
            $reqBody = $op['requestBody']['content']['application/json']['schema'] ?? null;
            if (is_array($reqBody)) {
                $reqProps = $reqBody['properties'] ?? [];
                $reqReqd  = $reqBody['required']   ?? [];
                if (is_array($reqProps)) {
                    foreach ($reqProps as $bp => $bpSchema) {
                        $bodyParams[] = $bp;
                        if (isset($jsonProps[$bp])) {
                            // Path/query parameter name collides with a body
                            // field. Body schema wins for the AI's purposes;
                            // resolver still routes path params into the URL.
                        }
                        $jsonProps[$bp] = is_array($bpSchema) ? $bpSchema : ['type' => 'string'];
                    }
                }
                if (is_array($reqReqd)) {
                    foreach ($reqReqd as $r) {
                        $bodyRequired[] = $r;
                        if (!in_array($r, $required, true)) $required[] = $r;
                    }
                }
            }

            // Destructive / safe / read flags
            $destructive = !empty($op['x-mv-destructive']);
            $safeWrite   = !empty($op['x-mv-safe-write']);
            $isRead      = !empty($op['x-mv-read']);
            // Auth defaults to true unless `security: []` is present.
            $auth = true;
            if (array_key_exists('security', $op) && is_array($op['security']) && count($op['security']) === 0) {
                $auth = false;
            }

            $tags = $op['tags'] ?? [];
            if (!is_array($tags)) $tags = [];

            $ops[] = [
                'operationId'  => $op['operationId'] ?? null,
                'method'       => strtoupper($methodLower),
                'path'         => $path,
                'summary'      => (string)($op['summary'] ?? ''),
                'description'  => (string)($op['description'] ?? ''),
                'tags'         => array_values(array_filter(array_map('strval', $tags))),
                'destructive'  => $destructive,
                'safe_write'   => $safeWrite,
                'read'         => $isRead,
                'auth'         => $auth,
                'path_params'  => $pathParams,
                'query_params' => $queryParams,
                'body_params'  => $bodyParams,
                'body_required'=> $bodyRequired,
                'parameters_schema' => [
                    'type'       => 'object',
                    'properties' => $jsonProps ?: new stdClass(),
                    'required'   => array_values(array_unique($required)),
                ],
            ];
        }
    }
    return $ops;
}

/**
 * Build the Groq/OpenAI tool array directly from the spec. Adds the
 * pseudo-tool listCapabilities so the AI can answer "what can you do?"
 * without inventing capabilities.
 */
function mv_openapi_to_tools(array $spec): array
{
    $tools = [];
    foreach (mv_openapi_operations($spec) as $op) {
        if (!$op['operationId']) continue;
        // Skip /health: the chatbot already pre-pings it before every turn.
        if ($op['method'] === 'GET' && $op['path'] === '/health') continue;

        $desc = trim(($op['summary'] ?? '') . '. ' . ($op['description'] ?? ''));
        if (function_exists('mb_substr')) {
            if (mb_strlen($desc) > 200) $desc = mb_substr($desc, 0, 197) . '...';
        } elseif (strlen($desc) > 200) {
            $desc = substr($desc, 0, 197) . '...';
        }

        $schema = $op['parameters_schema'];
        // Strip OpenAPI-specific nullable + drop empty required arrays so the
        // payload to the LLM stays the OpenAI function-calling shape.
        if (isset($schema['required']) && count($schema['required']) === 0) {
            unset($schema['required']);
        }
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name'        => $op['operationId'],
                'description' => $desc,
                'parameters'  => $schema,
            ],
        ];
    }

    // Pseudo-tool: listCapabilities. Not in the spec because it doesn't hit
    // the API — it answers from the loaded spec itself.
    $tools[] = [
        'type' => 'function',
        'function' => [
            'name'        => 'listCapabilities',
            'description' => 'List what the assistant can do, grouped by tag. Use when the user asks "what can you do?" or similar.',
            'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
        ],
    ];
    return $tools;
}

/**
 * Resolve a tool call to the HTTP request to make.
 * Returns ['method', 'path', 'query', 'body', 'destructive'] or null if the
 * operationId is unknown. listCapabilities is handled separately by the
 * caller (it has no HTTP call).
 */
function mv_openapi_resolve_call(array $spec, string $operationId, array $args): ?array
{
    foreach (mv_openapi_operations($spec) as $op) {
        if ($op['operationId'] !== $operationId) continue;

        $path = $op['path'];
        foreach ($op['path_params'] as $pp) {
            $val = $args[$pp] ?? '';
            $path = str_replace('{' . $pp . '}', rawurlencode((string)$val), $path);
        }

        $query = [];
        foreach ($op['query_params'] as $qp) {
            if (array_key_exists($qp, $args) && $args[$qp] !== null && $args[$qp] !== '') {
                $query[$qp] = $args[$qp];
            }
        }

        $body = [];
        foreach ($op['body_params'] as $bp) {
            if (array_key_exists($bp, $args) && $args[$bp] !== null && $args[$bp] !== '') {
                $body[$bp] = $args[$bp];
            }
        }

        return [
            'method'      => $op['method'],
            'path'        => $path,
            'query'       => $query,
            'body'        => $body,
            'destructive' => $op['destructive'],
        ];
    }
    return null;
}

/**
 * Categorized capability list used by the listCapabilities pseudo-tool.
 * Returns ['groups' => [tagName => [{operationId, summary}, ...]]].
 */
function mv_openapi_capabilities(array $spec): array
{
    $groups = [];
    foreach (mv_openapi_operations($spec) as $op) {
        if (!$op['operationId']) continue;
        $tag = $op['tags'][0] ?? 'Other';
        if (!isset($groups[$tag])) $groups[$tag] = [];
        $groups[$tag][] = [
            'operationId' => $op['operationId'],
            'summary'     => $op['summary'],
            'destructive' => $op['destructive'],
        ];
    }
    return ['groups' => $groups, 'spec_version' => $spec['info']['version'] ?? 'unknown'];
}

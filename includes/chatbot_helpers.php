<?php
/**
 * Pure helpers used by ai_chat.php — split out so tests/chatbot_unit_test.php
 * can include them without standing up sessions, DB connections, or HTTP.
 *
 * Anything here MUST be a pure function of its inputs — no $_SESSION, no
 * mysqli, no global state.
 *
 * Tool definitions are loaded from MyVivarium's OpenAPI specification at
 * api/openapi.yaml. The chatbot does NOT maintain a hardcoded tool list:
 *   - chatbot_all_tool_defs()  reads the spec and emits the OpenAI/Groq
 *     function-calling shape.
 *   - chatbot_resolve_tool()   maps an operationId + arguments back to the
 *     HTTP method, path, query, and body the API call should use.
 *
 * Adding a new endpoint means: implement it in api/index.php AND add the
 * matching path entry to api/openapi.yaml. The chatbot picks it up
 * automatically on the next request.
 */

require_once __DIR__ . '/../services/openapi_loader.php';

if (!function_exists('chatbot_resolve_tool')) {

    /**
     * Resolve a tool name + arguments to the HTTP call we should make.
     *
     * Returns ['method', 'path', 'query' => array, 'body' => array,
     *          'destructive' => bool], or null if the tool name is unknown.
     *
     * The pseudo-tool listCapabilities is handled by the caller (it answers
     * from the spec instead of hitting the API) so it returns null here.
     */
    function chatbot_resolve_tool(string $name, array $args): ?array
    {
        if ($name === 'listCapabilities') return null;
        try {
            $spec = mv_openapi_load();
        } catch (Throwable $e) {
            error_log('chatbot_resolve_tool spec load failed: ' . $e->getMessage());
            return null;
        }
        return mv_openapi_resolve_call($spec, $name, $args);
    }

    /**
     * Strip emails and phone numbers from a string before it goes to the LLM
     * (Groq or OpenAI). Same rules apply to either provider.
     */
    function chatbot_sanitize_for_llm(string $blob): string
    {
        $blob = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED]', $blob);
        $blob = preg_replace('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', '[REDACTED]', $blob);
        return $blob;
    }

    /**
     * Truncate a tool result to keep the per-call Groq payload bounded.
     * Default cap is 2500 chars; lists are additionally capped to 25 items
     * by chatbot_cap_list_result() before truncation.
     */
    function chatbot_truncate(string $s, int $max = 2500): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . ' ... [truncated]';
    }

    /**
     * Rough token estimate. char_length / 4 — adequate for budget gating.
     */
    function chatbot_estimate_tokens($payload): int
    {
        if (is_string($payload)) return (int)ceil(strlen($payload) / 4);
        if (is_array($payload)) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            return (int)ceil(strlen((string)$json) / 4);
        }
        return 0;
    }

    /**
     * For list_* tools the API may return an items[] array of arbitrary size.
     * Cap it to $max items and tag the JSON so Groq sees "showing N of M".
     * Returns the JSON string to feed back to Groq.
     */
    function chatbot_cap_list_result(string $toolName, string $rawJson, int $max = 25): string
    {
        // Operation IDs that start with `list` (or `getActivityLog` style)
        // tend to return paginated arrays. Be liberal here — false positives
        // just no-op.
        $prefix = strtolower(substr($toolName, 0, 4));
        if ($prefix !== 'list' && $prefix !== 'sear') {
            return $rawJson;
        }
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) return $rawJson;

        $list   = null;
        $setter = null;
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            if (array_is_list($decoded['data'])) {
                $list   = $decoded['data'];
                $setter = function ($capped, $total) use (&$decoded) {
                    $decoded['data'] = $capped;
                    $decoded['_truncated'] = "showing {$total} capped to " . count($capped);
                };
            } elseif (isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
                $list   = $decoded['data']['items'];
                $setter = function ($capped, $total) use (&$decoded) {
                    $decoded['data']['items']      = $capped;
                    $decoded['data']['_truncated'] = "showing {$total} capped to " . count($capped);
                };
            }
        }
        if ($list === null || count($list) <= $max) return $rawJson;

        $total = count($list);
        $capped = array_slice($list, 0, $max);
        $setter($capped, $total);
        return (string)json_encode($decoded, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Build the full OpenAI / Groq tool-definitions array from the OpenAPI
     * spec. operationId becomes the tool name, summary + description become
     * the tool description (capped to 200 chars), and the union of path /
     * query / body parameters becomes the JSON schema.
     */
    function chatbot_all_tool_defs(): array
    {
        try {
            $spec = mv_openapi_load();
        } catch (Throwable $e) {
            error_log('chatbot_all_tool_defs spec load failed: ' . $e->getMessage());
            return [];
        }
        return mv_openapi_to_tools($spec);
    }

    /**
     * Routing groups, derived from the OpenAPI tag of each operation. Used
     * by chatbot_select_tools() to send a smaller per-turn subset.
     * Returns ['group_name' => ['operationId', ...]].
     */
    function chatbot_tool_groups(): array
    {
        try {
            $spec = mv_openapi_load();
        } catch (Throwable $e) {
            return [];
        }
        $byTag = [];
        foreach (mv_openapi_operations($spec) as $op) {
            if (!$op['operationId']) continue;
            if ($op['method'] === 'GET' && $op['path'] === '/health') continue;
            $tag = $op['tags'][0] ?? 'Other';
            if (!isset($byTag[$tag])) $byTag[$tag] = [];
            $byTag[$tag][] = $op['operationId'];
        }
        return $byTag;
    }

    /**
     * Keyword router: pick the smallest tool subset that covers the user's
     * intent. If nothing matches, return the full tool set so the model can
     * still discover what's available.
     */
    function chatbot_select_tools(string $userMessage): array
    {
        $all = chatbot_all_tool_defs();
        if ($userMessage === '') return $all;

        $groups = chatbot_tool_groups();
        $msg    = strtolower($userMessage);
        $keep   = [];
        $hit    = false;

        // Mice-related → mice + maintenance + identity (so AI can use
        // listCapabilities / getMe in the same turn).
        if (preg_match('/\b(mouse|mice|pup|litter|sire|dam)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep,
                $groups['Mice'] ?? [],
                $groups['Holding Cages'] ?? [],
                $groups['Breeding Cages'] ?? []);
        }
        if (preg_match('/\b(cage|cages|holding|breeding|room)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep,
                $groups['Holding Cages'] ?? [],
                $groups['Breeding Cages'] ?? [],
                $groups['Maintenance Notes'] ?? [],
                $groups['Mice'] ?? []);
        }
        if (preg_match('/\b(note|notes|maintenance|water|bedding|food)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep,
                $groups['Maintenance Notes'] ?? [],
                $groups['Holding Cages'] ?? [],
                $groups['Breeding Cages'] ?? []);
        }
        if (preg_match('/\b(log|logs|history|activity|audit|who|when)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['Activity Log'] ?? []);
        }
        if (preg_match('/\b(me|my|profile|account|user)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['Users'] ?? []);
        }
        if (preg_match('/\b(what|capabilities|help|can you|able)\b/', $msg)) {
            $hit = true;
            $keep[] = 'listCapabilities';
        }

        if (!$hit) return $all;

        // listCapabilities is always useful; include it so the model can
        // tell the user what tools it does have when the routed subset
        // doesn't cover their ask.
        $keep[] = 'listCapabilities';
        $keep = array_unique($keep);

        return array_values(array_filter($all,
            fn($t) => in_array($t['function']['name'] ?? '', $keep, true)));
    }

    /**
     * Pseudo-tool implementation: builds a short, categorized capability
     * list so the AI can answer "what can you do?" without inventing tools.
     * Returns the JSON body to feed back to the LLM as the tool result.
     */
    function chatbot_list_capabilities(): array
    {
        try {
            $spec = mv_openapi_load();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'spec_unavailable'];
        }
        $cap = mv_openapi_capabilities($spec);
        return ['ok' => true, 'data' => $cap];
    }
}

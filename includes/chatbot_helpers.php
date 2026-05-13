<?php
/**
 * Pure helpers used by ai_chat.php — split out so tests/chatbot_unit_test.php
 * can include them without standing up sessions, DB connections, or HTTP.
 *
 * Anything here MUST be a pure function of its inputs — no $_SESSION, no
 * mysqli, no global state.
 */

if (!function_exists('chatbot_resolve_tool')) {

    /**
     * Resolve a tool name + arguments to the HTTP call we should make.
     *
     * Returns ['method', 'path', 'query' => array, 'body' => array,
     *          'destructive' => bool], or null if the tool name is unknown.
     */
    function chatbot_resolve_tool(string $name, array $args): ?array
    {
        switch ($name) {
            case 'get_me':
                return ['method' => 'GET', 'path' => '/me', 'query' => [], 'body' => [], 'destructive' => false];
            case 'list_mice':
                return ['method' => 'GET', 'path' => '/mice', 'query' => array_filter([
                    'status'  => $args['status']  ?? null,
                    'sex'     => $args['sex']     ?? null,
                    'strain'  => $args['strain']  ?? null,
                    'cage_id' => $args['cage_id'] ?? null,
                    'limit'   => $args['limit']   ?? null,
                ], fn($v) => $v !== null && $v !== ''), 'body' => [], 'destructive' => false];
            case 'get_mouse':
                return ['method' => 'GET', 'path' => '/mice/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => [], 'destructive' => false];
            case 'list_holding_cages':
                return ['method' => 'GET', 'path' => '/cages/holding', 'query' => array_filter(['limit' => $args['limit'] ?? null], fn($v) => $v !== null && $v !== ''), 'body' => [], 'destructive' => false];
            case 'get_holding_cage':
                return ['method' => 'GET', 'path' => '/cages/holding/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => [], 'destructive' => false];
            case 'list_breeding_cages':
                return ['method' => 'GET', 'path' => '/cages/breeding', 'query' => array_filter(['limit' => $args['limit'] ?? null], fn($v) => $v !== null && $v !== ''), 'body' => [], 'destructive' => false];
            case 'get_breeding_cage':
                return ['method' => 'GET', 'path' => '/cages/breeding/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => [], 'destructive' => false];
            case 'list_maintenance_notes':
                return ['method' => 'GET', 'path' => '/maintenance-notes', 'query' => array_filter([
                    'cage_id' => $args['cage_id'] ?? null,
                    'from'    => $args['from']    ?? null,
                    'to'      => $args['to']      ?? null,
                    'limit'   => $args['limit']   ?? null,
                ], fn($v) => $v !== null && $v !== ''), 'body' => [], 'destructive' => false];
            case 'get_maintenance_note':
                return ['method' => 'GET', 'path' => '/maintenance-notes/' . (int)($args['id'] ?? 0), 'query' => [], 'body' => [], 'destructive' => false];
            case 'search_activity_log':
                return ['method' => 'GET', 'path' => '/activity-log', 'query' => array_filter([
                    'user_id' => $args['user_id'] ?? null,
                    'action'  => $args['action']  ?? null,
                    'from'    => $args['from']    ?? null,
                    'to'      => $args['to']      ?? null,
                    'limit'   => $args['limit']   ?? null,
                ], fn($v) => $v !== null && $v !== ''), 'body' => [], 'destructive' => false];

            // ---- safe writes ----
            case 'add_maintenance_note':
                return ['method' => 'POST', 'path' => '/maintenance-notes', 'query' => [], 'body' => array_filter([
                    'cage_id'   => $args['cage_id']   ?? null,
                    'note_text' => $args['note_text'] ?? null,
                    'type'      => $args['type']      ?? null,
                ], fn($v) => $v !== null), 'destructive' => false];
            case 'create_holding_cage':
                $remarks = (string)($args['notes'] ?? '');
                if (!empty($args['capacity'])) {
                    $remarks = trim($remarks . ' [capacity ' . (int)$args['capacity'] . ']');
                }
                return ['method' => 'POST', 'path' => '/cages/holding', 'query' => [], 'body' => array_filter([
                    'cage_id' => $args['name'] ?? null,
                    'room'    => $args['room'] ?? null,
                    'remarks' => $remarks !== '' ? $remarks : null,
                ], fn($v) => $v !== null), 'destructive' => false];
            case 'create_breeding_cage':
                return ['method' => 'POST', 'path' => '/cages/breeding', 'query' => [], 'body' => array_filter([
                    'cage_id'   => $args['name']    ?? null,
                    'room'      => $args['room']    ?? null,
                    'male_id'   => $args['sire_id'] ?? null,
                    'female_id' => $args['dam_id']  ?? null,
                    'remarks'   => $args['notes']   ?? null,
                ], fn($v) => $v !== null), 'destructive' => false];
            case 'create_mouse':
                return ['method' => 'POST', 'path' => '/mice', 'query' => [], 'body' => array_filter([
                    'mouse_id' => $args['mouse_id'] ?? null,
                    'cage_id'  => $args['cage_id']  ?? null,
                    'sex'      => $args['sex']      ?? null,
                    'strain'   => $args['strain']   ?? null,
                    'dob'      => $args['dob']      ?? null,
                    'genotype' => $args['genotype'] ?? null,
                    'notes'    => $args['notes']    ?? null,
                ], fn($v) => $v !== null && $v !== ''), 'destructive' => false];

            // ---- destructive writes ----
            case 'update_mouse':
                return ['method' => 'PATCH', 'path' => '/mice/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => is_array($args['fields'] ?? null) ? $args['fields'] : [], 'destructive' => true];
            case 'move_mouse':
                return ['method' => 'POST', 'path' => '/mice/' . rawurlencode((string)($args['id'] ?? '')) . '/move', 'query' => [], 'body' => array_filter([
                    'to_cage_id' => $args['to_cage_id'] ?? null,
                    'reason'     => $args['reason']     ?? null,
                ], fn($v) => $v !== null), 'destructive' => true];
            case 'sacrifice_mouse':
                return ['method' => 'POST', 'path' => '/mice/' . rawurlencode((string)($args['id'] ?? '')) . '/sacrifice', 'query' => [], 'body' => array_filter([
                    'date'   => $args['date']   ?? null,
                    'reason' => $args['reason'] ?? null,
                ], fn($v) => $v !== null), 'destructive' => true];
            case 'delete_mouse':
                return ['method' => 'DELETE', 'path' => '/mice/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => [], 'destructive' => true];
            case 'update_holding_cage':
                return ['method' => 'PATCH', 'path' => '/cages/holding/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => is_array($args['fields'] ?? null) ? $args['fields'] : [], 'destructive' => true];
            case 'update_breeding_cage':
                return ['method' => 'PATCH', 'path' => '/cages/breeding/' . rawurlencode((string)($args['id'] ?? '')), 'query' => [], 'body' => is_array($args['fields'] ?? null) ? $args['fields'] : [], 'destructive' => true];
            case 'edit_maintenance_note':
                return ['method' => 'PATCH', 'path' => '/maintenance-notes/' . (int)($args['id'] ?? 0), 'query' => [], 'body' => array_filter([
                    'note_text' => $args['note_text'] ?? null,
                ], fn($v) => $v !== null), 'destructive' => true];
            case 'delete_maintenance_note':
                return ['method' => 'DELETE', 'path' => '/maintenance-notes/' . (int)($args['id'] ?? 0), 'query' => [], 'body' => [], 'destructive' => true];
        }
        return null;
    }

    /**
     * Strip emails and phone numbers from a string before it goes to Groq.
     */
    function chatbot_sanitize_for_groq(string $blob): string
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
        if (strncmp($toolName, 'list_', 5) !== 0 && $toolName !== 'search_activity_log') {
            return $rawJson;
        }
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) return $rawJson;

        // The REST envelope is { ok, data: [...] } or { ok, data: { items: [...] } }.
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
     * Build the full tool-definitions array Groq expects (OpenAI function-
     * calling shape). Descriptions are kept short (40-80 chars) so the
     * whole block stays well under 2k tokens. Parameter schemas drop verbose
     * examples; only short field hints remain.
     */
    function chatbot_all_tool_defs(): array
    {
        return [
            // ---- read_mice ----
            ['type' => 'function', 'function' => [
                'name' => 'get_me',
                'description' => 'Get the signed-in user (id, name, email, role).',
                'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_mice',
                'description' => 'List mice with optional status/sex/strain/cage filters.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'status'  => ['type' => 'string', 'description' => 'alive|sacrificed|transferred_out|archived'],
                    'sex'     => ['type' => 'string', 'description' => 'male|female|unknown'],
                    'strain'  => ['type' => 'string'],
                    'cage_id' => ['type' => 'string'],
                    'limit'   => ['type' => 'integer'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_mouse',
                'description' => 'Fetch full details for one mouse by id.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ]],

            // ---- read_cages ----
            ['type' => 'function', 'function' => [
                'name' => 'list_holding_cages',
                'description' => 'List active holding cages with optional limit.',
                'parameters' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_holding_cage',
                'description' => 'Fetch full details for one holding cage by id.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'list_breeding_cages',
                'description' => 'List active breeding cages with optional limit.',
                'parameters' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_breeding_cage',
                'description' => 'Fetch full details for one breeding cage by id.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ]],

            // ---- maintenance reads ----
            ['type' => 'function', 'function' => [
                'name' => 'list_maintenance_notes',
                'description' => 'List maintenance notes; filter by cage/date.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'cage_id' => ['type' => 'string'],
                    'from'    => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'to'      => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'limit'   => ['type' => 'integer'],
                ]],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'get_maintenance_note',
                'description' => 'Fetch one maintenance note by id.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ]],

            // ---- read_logs ----
            ['type' => 'function', 'function' => [
                'name' => 'search_activity_log',
                'description' => 'Search the audit log by user, action, or date range.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'user_id' => ['type' => 'integer'],
                    'action'  => ['type' => 'string'],
                    'from'    => ['type' => 'string'],
                    'to'      => ['type' => 'string'],
                    'limit'   => ['type' => 'integer'],
                ]],
            ]],

            // ---- write_safe ----
            ['type' => 'function', 'function' => [
                'name' => 'add_maintenance_note',
                'description' => 'Add a maintenance note to a cage.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'cage_id'   => ['type' => 'string'],
                    'note_text' => ['type' => 'string'],
                    'type'      => ['type' => 'string', 'description' => 'category, e.g. water, bedding'],
                ], 'required' => ['cage_id', 'note_text']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_holding_cage',
                'description' => 'Create a new holding cage (name, room, notes).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'name'     => ['type' => 'string', 'description' => 'cage_id label'],
                    'room'     => ['type' => 'string'],
                    'capacity' => ['type' => 'integer'],
                    'notes'    => ['type' => 'string'],
                ], 'required' => ['name']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_breeding_cage',
                'description' => 'Create a breeding cage with a sire and dam.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'name'    => ['type' => 'string', 'description' => 'cage_id label'],
                    'room'    => ['type' => 'string'],
                    'sire_id' => ['type' => 'string', 'description' => 'male mouse_id'],
                    'dam_id'  => ['type' => 'string', 'description' => 'female mouse_id'],
                    'notes'   => ['type' => 'string'],
                ], 'required' => ['name']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'create_mouse',
                'description' => 'Register a new mouse in the colony.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'mouse_id' => ['type' => 'string'],
                    'cage_id'  => ['type' => 'string'],
                    'sex'      => ['type' => 'string'],
                    'strain'   => ['type' => 'string'],
                    'dob'      => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'genotype' => ['type' => 'string'],
                    'notes'    => ['type' => 'string'],
                ], 'required' => ['mouse_id']],
            ]],

            // ---- write_destructive (confirmation gated) ----
            ['type' => 'function', 'function' => [
                'name' => 'update_mouse',
                'description' => 'Update mouse fields; needs user confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'     => ['type' => 'string'],
                    'fields' => ['type' => 'object', 'description' => 'field => new value map'],
                ], 'required' => ['id', 'fields']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'move_mouse',
                'description' => 'Move a mouse to another cage; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'         => ['type' => 'string'],
                    'to_cage_id' => ['type' => 'string'],
                    'reason'     => ['type' => 'string'],
                ], 'required' => ['id', 'to_cage_id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'sacrifice_mouse',
                'description' => 'Mark a mouse sacrificed; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'     => ['type' => 'string'],
                    'date'   => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ], 'required' => ['id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_mouse',
                'description' => 'Archive a mouse; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_holding_cage',
                'description' => 'Update holding cage fields; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'     => ['type' => 'string'],
                    'fields' => ['type' => 'object'],
                ], 'required' => ['id', 'fields']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'update_breeding_cage',
                'description' => 'Update breeding cage fields; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'     => ['type' => 'string'],
                    'fields' => ['type' => 'object'],
                ], 'required' => ['id', 'fields']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'edit_maintenance_note',
                'description' => 'Edit a maintenance note; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'id'        => ['type' => 'integer'],
                    'note_text' => ['type' => 'string'],
                ], 'required' => ['id', 'note_text']],
            ]],
            ['type' => 'function', 'function' => [
                'name' => 'delete_maintenance_note',
                'description' => 'Delete a maintenance note; needs confirmation.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ]],
        ];
    }

    /**
     * Static map of group => tool-name list. Mirrors chatbot_all_tool_defs().
     */
    function chatbot_tool_groups(): array
    {
        return [
            'read_mice'         => ['get_me', 'list_mice', 'get_mouse'],
            'read_cages'        => ['list_holding_cages', 'get_holding_cage', 'list_breeding_cages', 'get_breeding_cage'],
            'read_maintenance'  => ['list_maintenance_notes', 'get_maintenance_note'],
            'read_logs'         => ['search_activity_log'],
            'write_safe'        => ['add_maintenance_note', 'create_holding_cage', 'create_breeding_cage', 'create_mouse'],
            'write_destructive' => ['update_mouse', 'move_mouse', 'sacrifice_mouse', 'delete_mouse',
                                    'update_holding_cage', 'update_breeding_cage',
                                    'edit_maintenance_note', 'delete_maintenance_note'],
        ];
    }

    /**
     * Keyword router: pick the smallest group set that covers the user's
     * intent. If nothing matches, return all tools so Groq isn't blind.
     */
    function chatbot_select_tools(string $userMessage): array
    {
        $all    = chatbot_all_tool_defs();
        $groups = chatbot_tool_groups();
        $msg    = strtolower($userMessage);

        if ($msg === '') return $all;

        $keep = [];
        $hit  = false;
        if (preg_match('/\b(mouse|mice|pup|litter|sire|dam)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['read_mice'], $groups['write_safe'], $groups['write_destructive']);
        }
        if (preg_match('/\b(cage|cages|holding|breeding|room)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['read_cages'], $groups['write_safe'], $groups['write_destructive']);
        }
        if (preg_match('/\b(note|notes|maintenance|water|bedding|food)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['read_maintenance'], $groups['write_safe'], $groups['write_destructive']);
        }
        if (preg_match('/\b(log|logs|history|activity|audit|who|when)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['read_logs']);
        }
        if (preg_match('/\b(me|my|profile|account|user)\b/', $msg)) {
            $hit = true;
            $keep = array_merge($keep, $groups['read_mice']); // get_me lives here
        }

        if (!$hit) return $all;

        $keep = array_unique($keep);
        return array_values(array_filter($all, fn($t) => in_array($t['function']['name'] ?? '', $keep, true)));
    }
}

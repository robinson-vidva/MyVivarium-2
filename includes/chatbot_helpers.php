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
     *
     * TODO: stronger anonymization — tokenize IDs and de-tokenize on render
     */
    function chatbot_sanitize_for_groq(string $blob): string
    {
        $blob = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED]', $blob);
        $blob = preg_replace('/\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/', '[REDACTED]', $blob);
        return $blob;
    }

    /**
     * Truncate to keep tool results from blowing the Groq context budget.
     */
    function chatbot_truncate(string $s, int $max = 8000): string
    {
        if (strlen($s) <= $max) return $s;
        return substr($s, 0, $max) . ' ... [truncated]';
    }
}

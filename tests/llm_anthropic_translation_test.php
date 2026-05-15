<?php
/**
 * Unit tests for the Anthropic Messages translation adapter.
 *
 * Verifies that OpenAI-shape input ⇄ Anthropic-shape output translates
 * cleanly in both directions, including the tricky cases:
 *
 *   - system prompt is hoisted from the messages array to a top-level field
 *   - assistant messages with tool_calls become content blocks with type
 *     'tool_use' and the function arguments JSON-string is parsed back to
 *     an object
 *   - tool result messages (role 'tool') become user messages with type
 *     'tool_result' content blocks
 *   - tool definitions translate from {type:'function', function:{…}} into
 *     the Anthropic {name, description, input_schema} shape
 *   - Anthropic responses with mixed text + tool_use content blocks come
 *     back as one OpenAI-shape choices[0].message with content + tool_calls
 *   - usage is mapped: input_tokens→prompt_tokens, output_tokens→completion_tokens
 *   - a multi-turn conversation round-trips without losing semantics
 *
 *     php tests/llm_anthropic_translation_test.php
 */

// Stub ai_settings_get() so llm_provider.php loads without a DB.
class AiSettingsException extends RuntimeException {}
function ai_settings_get(string $key): ?string { return null; }
require_once __DIR__ . '/../includes/llm_provider.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { echo "[PASS] $name\n"; $pass++; }
    else     { echo "[FAIL] $name\n"; $fail++; }
}

// ---------------------------------------------------------------------------
// llm_extract_system_prompt() — first 'system' message is removed and
// returned separately; subsequent system messages stay in place.
// ---------------------------------------------------------------------------

$msgs = [
    ['role' => 'system', 'content' => 'You are a lab assistant.'],
    ['role' => 'user',   'content' => 'hi'],
];
[$sys, $rest] = llm_extract_system_prompt($msgs);
check('extract: system pulled out',
    $sys === 'You are a lab assistant.' && count($rest) === 1 && $rest[0]['role'] === 'user');

[$sys, $rest] = llm_extract_system_prompt([['role' => 'user', 'content' => 'hi']]);
check('extract: no system → null',
    $sys === null && count($rest) === 1);

// ---------------------------------------------------------------------------
// llm_translate_messages_to_anthropic() — single-turn user
// ---------------------------------------------------------------------------

$out = llm_translate_messages_to_anthropic([
    ['role' => 'user', 'content' => 'list cages'],
], 'Be terse.');
check('user: system hoisted', $out['system'] === 'Be terse.');
check('user: one message',     count($out['messages']) === 1 && $out['messages'][0]['role'] === 'user');
check('user: content is text block array',
    is_array($out['messages'][0]['content'])
    && $out['messages'][0]['content'][0]['type'] === 'text'
    && $out['messages'][0]['content'][0]['text'] === 'list cages');

// ---------------------------------------------------------------------------
// Assistant with tool_calls
// ---------------------------------------------------------------------------

$openaiTurn = [
    ['role' => 'user', 'content' => 'list HCs'],
    [
        'role'       => 'assistant',
        'content'    => 'looking up...',
        'tool_calls' => [
            [
                'id'       => 'call_1',
                'type'     => 'function',
                'function' => [
                    'name'      => 'listHoldingCages',
                    'arguments' => '{"limit":5}',
                ],
            ],
        ],
    ],
    ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true,"data":[]}'],
];
$out = llm_translate_messages_to_anthropic($openaiTurn, null);
check('tool-call: produces 3 messages',     count($out['messages']) === 3);
check('tool-call: assistant text block kept',
    $out['messages'][1]['role'] === 'assistant'
    && $out['messages'][1]['content'][0]['type'] === 'text'
    && $out['messages'][1]['content'][0]['text'] === 'looking up...');
check('tool-call: assistant has tool_use block w/ id/name/input',
    $out['messages'][1]['content'][1]['type'] === 'tool_use'
    && $out['messages'][1]['content'][1]['id']   === 'call_1'
    && $out['messages'][1]['content'][1]['name'] === 'listHoldingCages');
// $input may be an object (cast) or array; either way it must round-trip.
$input = $out['messages'][1]['content'][1]['input'];
$inputArr = is_object($input) ? (array)$input : (array)$input;
check('tool-call: arguments JSON-string parsed back to object/array',
    isset($inputArr['limit']) && (int)$inputArr['limit'] === 5);
check('tool-call: tool result becomes role=user + tool_result block',
    $out['messages'][2]['role'] === 'user'
    && $out['messages'][2]['content'][0]['type']         === 'tool_result'
    && $out['messages'][2]['content'][0]['tool_use_id']  === 'call_1'
    && strpos($out['messages'][2]['content'][0]['content'], 'data') !== false);

// Assistant with tool_calls only (no text) still produces valid content
// (Anthropic requires at least one content block).
$out2 = llm_translate_messages_to_anthropic([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id'=>'c1', 'type'=>'function', 'function'=>['name'=>'getMe', 'arguments'=>'{}']],
    ]],
], null);
check('tool-call: assistant with no text still produces a content block',
    count($out2['messages'][0]['content']) === 1
    && $out2['messages'][0]['content'][0]['type'] === 'tool_use');

// Tool call args may be an array (not always a JSON string) — accept both.
$out3 = llm_translate_messages_to_anthropic([
    ['role' => 'assistant', 'content' => '', 'tool_calls' => [
        ['id'=>'c1', 'type'=>'function', 'function'=>['name'=>'x', 'arguments'=>['a'=>1]]],
    ]],
], null);
$inputArr = (array)$out3['messages'][0]['content'][0]['input'];
check('tool-call: arguments accepts array as well as string',
    isset($inputArr['a']) && $inputArr['a'] === 1);

// Multi-turn conversation: 4-message conversation with two tool round trips.
$multi = [
    ['role'=>'system','content'=>'sys'],
    ['role'=>'user','content'=>'q1'],
    ['role'=>'assistant','content'=>'','tool_calls'=>[['id'=>'a','type'=>'function','function'=>['name'=>'f1','arguments'=>'{}']]]],
    ['role'=>'tool','tool_call_id'=>'a','content'=>'r1'],
    ['role'=>'assistant','content'=>'half-answer','tool_calls'=>[['id'=>'b','type'=>'function','function'=>['name'=>'f2','arguments'=>'{"x":1}']]]],
    ['role'=>'tool','tool_call_id'=>'b','content'=>'r2'],
    ['role'=>'assistant','content'=>'final answer'],
];
$out = llm_translate_messages_to_anthropic($multi, null);
check('multi: system hoisted from messages array',  $out['system'] === 'sys');
check('multi: 6 turns of non-system kept',          count($out['messages']) === 6);
check('multi: tool_results land as role=user',      $out['messages'][2]['role'] === 'user' && $out['messages'][4]['role'] === 'user');
check('multi: tool_results carry the right tool_use_id',
    $out['messages'][2]['content'][0]['tool_use_id'] === 'a'
    && $out['messages'][4]['content'][0]['tool_use_id'] === 'b');
check('multi: final assistant has plain text content',
    $out['messages'][5]['role'] === 'assistant'
    && $out['messages'][5]['content'][0]['type'] === 'text'
    && $out['messages'][5]['content'][0]['text'] === 'final answer');

// ---------------------------------------------------------------------------
// llm_translate_tools_to_anthropic() — function→tool with input_schema
// ---------------------------------------------------------------------------

$openaiTools = [
    ['type'=>'function','function'=>[
        'name'=>'listMice',
        'description'=>'List mice in colony',
        'parameters'=>['type'=>'object','properties'=>['status'=>['type'=>'string']],'required'=>['status']],
    ]],
    ['type'=>'function','function'=>[
        'name'=>'getMe',
        'description'=>'Current user',
        'parameters'=>['type'=>'object','properties'=>(object)[]],
    ]],
];
$tools = llm_translate_tools_to_anthropic($openaiTools);
check('tools: two tools translated',         count($tools) === 2);
check('tools: name + description preserved',
    $tools[0]['name'] === 'listMice' && $tools[0]['description'] === 'List mice in colony');
check('tools: parameters → input_schema',
    isset($tools[0]['input_schema']['properties']['status'])
    && in_array('status', $tools[0]['input_schema']['required'], true));
check('tools: parameterless tool still has input_schema with type=object',
    $tools[1]['input_schema']['type'] === 'object');
check('tools: function with missing name is dropped',
    count(llm_translate_tools_to_anthropic([['type'=>'function','function'=>['description'=>'no name']]])) === 0);

// ---------------------------------------------------------------------------
// llm_translate_anthropic_response() — back to OpenAI shape
// ---------------------------------------------------------------------------

$anthropic = [
    'id'      => 'msg_1',
    'model'   => 'claude-haiku-4-5',
    'content' => [
        ['type'=>'text','text'=>'I will look up the cages.'],
        ['type'=>'tool_use','id'=>'tu_1','name'=>'listHoldingCages','input'=>['limit'=>10]],
    ],
    'stop_reason' => 'tool_use',
    'usage'   => ['input_tokens' => 42, 'output_tokens' => 7],
];
$res = llm_translate_anthropic_response($anthropic);
check('resp: top-level id + model preserved',
    $res['id'] === 'msg_1' && $res['model'] === 'claude-haiku-4-5');
check('resp: choices[0].message.role = assistant',
    $res['choices'][0]['message']['role'] === 'assistant');
check('resp: text content concatenated',
    $res['choices'][0]['message']['content'] === 'I will look up the cages.');
check('resp: tool_use becomes tool_calls entry',
    $res['choices'][0]['message']['tool_calls'][0]['function']['name'] === 'listHoldingCages');
check('resp: tool_calls.arguments is a JSON string of the input',
    is_string($res['choices'][0]['message']['tool_calls'][0]['function']['arguments'])
    && json_decode($res['choices'][0]['message']['tool_calls'][0]['function']['arguments'], true)['limit'] === 10);
check('resp: finish_reason maps tool_use → tool_calls',
    $res['choices'][0]['finish_reason'] === 'tool_calls');
check('resp: usage mapped',
    $res['usage']['prompt_tokens'] === 42 && $res['usage']['completion_tokens'] === 7
    && $res['usage']['total_tokens'] === 49);

// Pure-text response, no tool_use → no tool_calls in the message
$plain = llm_translate_anthropic_response([
    'id'=>'msg_2','model'=>'x','content'=>[['type'=>'text','text'=>'hello']],
    'stop_reason'=>'end_turn','usage'=>['input_tokens'=>1,'output_tokens'=>1],
]);
check('resp: plain text response has no tool_calls key',
    !isset($plain['choices'][0]['message']['tool_calls']));
check('resp: end_turn maps to finish_reason=stop',
    $plain['choices'][0]['finish_reason'] === 'stop');

// max_tokens stop → finish_reason=length
$mx = llm_translate_anthropic_response([
    'id'=>'m','model'=>'x','content'=>[['type'=>'text','text'=>'cut off']],
    'stop_reason'=>'max_tokens','usage'=>['input_tokens'=>0,'output_tokens'=>0],
]);
check('resp: max_tokens stop → length', $mx['choices'][0]['finish_reason'] === 'length');

// Multiple text blocks concatenate in order
$multi = llm_translate_anthropic_response([
    'id'=>'m','model'=>'x','content'=>[
        ['type'=>'text','text'=>'one '],
        ['type'=>'text','text'=>'two '],
        ['type'=>'text','text'=>'three'],
    ],
    'stop_reason'=>'end_turn','usage'=>['input_tokens'=>0,'output_tokens'=>0],
]);
check('resp: multiple text blocks concatenate in order',
    $multi['choices'][0]['message']['content'] === 'one two three');

// ---------------------------------------------------------------------------
// Round-trip: OpenAI → Anthropic → OpenAI tool_call should preserve name +
// arguments. This is the path that actually runs inside llm_chat_completions
// when an Anthropic provider is configured.
// ---------------------------------------------------------------------------

// Step 1: pretend the chatbot wants to call a tool with these args.
$origArgs = ['status' => 'alive', 'limit' => 10];
$openaiMessages = [
    ['role' => 'user', 'content' => 'show alive mice'],
];
$translated = llm_translate_messages_to_anthropic($openaiMessages, null);
// Imagine the Anthropic API responds with a tool_use block. Mimic that.
$fakeAnthropicResponse = [
    'id'=>'msg_x','model'=>'claude-x',
    'content'=>[
        ['type'=>'text','text'=>'fetching…'],
        ['type'=>'tool_use','id'=>'tu_x','name'=>'listMice','input'=>$origArgs],
    ],
    'stop_reason'=>'tool_use',
    'usage'=>['input_tokens'=>5,'output_tokens'=>2],
];
$openaiResp = llm_translate_anthropic_response($fakeAnthropicResponse);
$toolCall = $openaiResp['choices'][0]['message']['tool_calls'][0] ?? null;
$decoded = is_array($toolCall) ? json_decode($toolCall['function']['arguments'], true) : null;
check('roundtrip: tool_call args survive Anthropic round trip',
    is_array($decoded) && $decoded['status'] === 'alive' && $decoded['limit'] === 10);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

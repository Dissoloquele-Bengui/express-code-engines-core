<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\ChatEngine\ChatEngine;
use ExpressCodeEngines\Extended\ChatEngine\Memory\CacheMemory;
use ExpressCodeEngines\Extended\ChatEngine\PersonaRegistry;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ChatMemoryInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentEngineInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\ChatPersona;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\ModelConfig;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;
use ExpressCodeEngines\Shared\ValueObjects\ChatResponse;
use ExpressCodeEngines\Shared\ValueObjects\DocumentResult;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function makeAiEngine(string $answer = 'Test answer', bool $fails = false): AiEngineInterface
{
    return new class ($answer, $fails) implements AiEngineInterface {
        public array $calls = [];
        public function __construct(private string $answer, private bool $fails) {}

        public function complete(AiRequest $req): AiResponse
        {
            $this->calls[] = $req;
            if ($this->fails) return AiResponse::failed('AI_ERROR', 'Provider down.');
            return AiResponse::ok($this->answer, 'test', 'model', 20, 10, 0.001);
        }

        public function embed(string $text, string $provider = 'openai'): array { return []; }
    };
}

function makeDocEngine(array $chunks = []): DocumentEngineInterface
{
    return new class ($chunks) implements DocumentEngineInterface {
        public function __construct(private array $chunks) {}
        public function process(\ExpressCodeEngines\Shared\DTOs\DocumentInput $i): DocumentResult
        {
            return DocumentResult::ok('doc-1', 'text', 1);
        }
        public function retrieve(string $query, array $entities = [], int $topK = 5): array
        {
            return $this->chunks;
        }
        public function delete(string $id): bool { return true; }
    };
}

function makeMemory(): ChatMemoryInterface
{
    return new class implements ChatMemoryInterface {
        public array $sessions = [];

        public function load(string $sessionId): array
        {
            return $this->sessions[$sessionId] ?? [];
        }
        public function save(string $sessionId, array $messages): void
        {
            $this->sessions[$sessionId] = $messages;
        }
        public function append(string $sessionId, AiMessage $message): void
        {
            $this->sessions[$sessionId][] = $message;
        }
        public function clear(string $sessionId): void
        {
            unset($this->sessions[$sessionId]);
        }
    };
}

function makeDefaultPersona(bool $ragEnabled = false): ChatPersona
{
    return new ChatPersona(
        key:          'default',
        systemPrompt: 'You are a helpful assistant.',
        model:        new ModelConfig(provider: 'test', model: 'test-model'),
        ragEnabled:   $ragEnabled,
        ragTopK:      3,
        citeSources:  true,
    );
}

function makeChat(
    AiEngineInterface       $ai      = null,
    DocumentEngineInterface $docs    = null,
    ChatMemoryInterface     $memory  = null,
    ChatPersona             $persona = null,
): ChatEngine {
    $registry = new PersonaRegistry();
    $registry->register($persona ?? makeDefaultPersona());

    return new ChatEngine(
        ai:        $ai      ?? makeAiEngine(),
        documents: $docs    ?? makeDocEngine(),
        memory:    $memory  ?? makeMemory(),
        personas:  $registry,
    );
}

function chatMsg(string $content = 'Hello', string $session = 'sess-1', string $persona = 'default'): ChatMessage
{
    return new ChatMessage(content: $content, sessionId: $session, persona: $persona);
}

// ──────────────────────────────────────────────────────────────
// Basic chat
// ──────────────────────────────────────────────────────────────

it('returns successful response with answer', function () {
    $engine   = makeChat(ai: makeAiEngine('Hello, how can I help?'));
    $response = $engine->chat(chatMsg('Hi'));

    expect($response->isFailed())->toBeFalse()
        ->and($response->answer)->toBe('Hello, how can I help?')
        ->and($response->sessionId)->toBe('sess-1')
        ->and($response->persona)->toBe('default');
});

it('returns failed response when AiEngine fails', function () {
    $engine   = makeChat(ai: makeAiEngine(fails: true));
    $response = $engine->chat(chatMsg('Hi'));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('AI_ERROR');
});

it('tracks token usage and cost from AiEngine response', function () {
    $engine   = makeChat(ai: makeAiEngine('Response'));
    $response = $engine->chat(chatMsg('Question'));

    expect($response->inputTokens)->toBe(20)
        ->and($response->outputTokens)->toBe(10)
        ->and($response->estimatedCost)->toBe(0.001);
});

// ──────────────────────────────────────────────────────────────
// Session memory
// ──────────────────────────────────────────────────────────────

it('persists user and assistant messages to memory after each turn', function () {
    $memory = makeMemory();
    $engine = makeChat(ai: makeAiEngine('Answer'), memory: $memory);

    $engine->chat(chatMsg('Question', 'sess-1'));

    expect($memory->sessions['sess-1'])->toHaveCount(2)
        ->and($memory->sessions['sess-1'][0]->role)->toBe('user')
        ->and($memory->sessions['sess-1'][0]->content)->toBe('Question')
        ->and($memory->sessions['sess-1'][1]->role)->toBe('assistant')
        ->and($memory->sessions['sess-1'][1]->content)->toBe('Answer');
});

it('includes session history in subsequent messages', function () {
    $ai     = makeAiEngine('Second answer');
    $memory = makeMemory();
    $engine = makeChat(ai: $ai, memory: $memory);

    $engine->chat(chatMsg('First question', 'sess-1'));
    $engine->chat(chatMsg('Second question', 'sess-1'));

    // Second call must include prior history in the messages sent to AiEngine
    $secondCallMessages = $ai->calls[1]->messages;
    $roles = array_map(fn ($m) => $m->role, $secondCallMessages);

    expect(in_array('user', $roles))->toBeTrue()
        ->and(in_array('assistant', $roles))->toBeTrue();
});

it('clears session history', function () {
    $memory = makeMemory();
    $engine = makeChat(memory: $memory);

    $engine->chat(chatMsg('Hello', 'sess-1'));
    $engine->clearSession('sess-1');

    expect($engine->history('sess-1'))->toBe([]);
});

it('returns session history', function () {
    $memory = makeMemory();
    $engine = makeChat(ai: makeAiEngine('Answer'), memory: $memory);

    $engine->chat(chatMsg('Hello', 'sess-1'));
    $history = $engine->history('sess-1');

    expect($history)->toHaveCount(2);
});

// ──────────────────────────────────────────────────────────────
// RAG integration
// ──────────────────────────────────────────────────────────────

it('includes RAG context in the system message when ragEnabled', function () {
    $ai      = makeAiEngine('RAG answer');
    $chunks  = [
        new DocumentChunk('Important context text.', 0, 'doc-abc', []),
    ];
    $docs    = makeDocEngine($chunks);
    $persona = makeDefaultPersona(ragEnabled: true);

    $engine  = makeChat(ai: $ai, docs: $docs, persona: $persona);
    $engine->chat(chatMsg('Question'));

    // System message should contain the chunk text
    $systemMessage = $ai->calls[0]->messages[0];
    expect($systemMessage->role)->toBe('system')
        ->and($systemMessage->content)->toContain('Important context text.');
});

it('does not call DocumentEngine when ragEnabled is false', function () {
    $docsCalled = false;

    $docs = new class ($docsCalled) implements DocumentEngineInterface {
        public function __construct(private bool &$called) {}
        public function process(\ExpressCodeEngines\Shared\DTOs\DocumentInput $i): DocumentResult { return DocumentResult::ok('x', '', 0); }
        public function retrieve(string $q, array $e = [], int $k = 5): array { $this->called = true; return []; }
        public function delete(string $id): bool { return true; }
    };

    $engine = makeChat(docs: $docs, persona: makeDefaultPersona(ragEnabled: false));
    $engine->chat(chatMsg('Question'));

    expect($docsCalled)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// Citation extraction
// ──────────────────────────────────────────────────────────────

it('extracts citations from answer text when citeSources is true', function () {
    $chunks = [
        new DocumentChunk('Source text about orders.', 0, 'doc-xyz', []),
    ];

    $ai      = makeAiEngine('The answer is here [Source doc-xyz#0] as per the document.');
    $persona = new ChatPersona(
        key: 'default', systemPrompt: 'You help.',
        model: new ModelConfig('test', 'model'),
        ragEnabled: true, citeSources: true,
    );

    $engine   = makeChat(ai: $ai, docs: makeDocEngine($chunks), persona: $persona);
    $response = $engine->chat(chatMsg('Question'));

    expect($response->hasSources())->toBeTrue()
        ->and($response->sources[0]['document_id'])->toBe('doc-xyz')
        ->and($response->sources[0]['chunk_index'])->toBe(0)
        ->and($response->sources[0]['excerpt'])->toContain('Source text about orders.');
});

it('deduplicates citations when same source is referenced multiple times', function () {
    $chunks = [new DocumentChunk('Text.', 0, 'doc-1', [])];
    $ai     = makeAiEngine('[Source doc-1#0] first mention and [Source doc-1#0] again.');
    $persona = makeDefaultPersona(ragEnabled: true);
    $persona = new ChatPersona(
        'default', 'Help.', new ModelConfig('test', 'model'),
        ragEnabled: true, citeSources: true,
    );

    $engine   = makeChat(ai: $ai, docs: makeDocEngine($chunks), persona: $persona);
    $response = $engine->chat(chatMsg('Q'));

    expect($response->sources)->toHaveCount(1);
});

it('returns no sources when citeSources is false', function () {
    $persona = new ChatPersona(
        'default', 'Help.', new ModelConfig('test', 'model'),
        ragEnabled: false, citeSources: false,
    );

    $engine   = makeChat(persona: $persona);
    $response = $engine->chat(chatMsg('Q'));

    expect($response->sources)->toBe([]);
});

// ──────────────────────────────────────────────────────────────
// PersonaRegistry
// ──────────────────────────────────────────────────────────────

it('resolves persona by key', function () {
    $registry = new PersonaRegistry();
    $registry->register(makeDefaultPersona());

    expect($registry->find('default'))->not->toBeNull()
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('returns fallback persona when key not found', function () {
    $registry = new PersonaRegistry();
    $registry->register(makeDefaultPersona());

    $persona = $registry->findOrDefault('missing');
    expect($persona->key)->toBe('default');
});

it('builds fallback persona when registry is empty', function () {
    $registry = new PersonaRegistry();
    $persona  = $registry->findOrDefault('anything');

    expect($persona->key)->toBe('default')
        ->and($persona->ragEnabled)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// CacheMemory — unit tests
// ──────────────────────────────────────────────────────────────

it('CacheMemory trims old messages beyond maxMessages', function () {
    // Use a spy cache that stores in memory
    \Illuminate\Support\Facades\Cache::shouldReceive('get')->andReturnUsing(function ($key, $default) use (&$store) {
        return $store[$key] ?? $default;
    });
    \Illuminate\Support\Facades\Cache::shouldReceive('put')->andReturnUsing(function ($key, $value) use (&$store) {
        $store[$key] = $value;
    });
    \Illuminate\Support\Facades\Cache::shouldReceive('forget')->andReturnUsing(function ($key) use (&$store) {
        unset($store[$key]);
    });

    $store  = [];
    $memory = new CacheMemory(maxMessages: 3);

    for ($i = 0; $i < 5; $i++) {
        $memory->append('sess', AiMessage::user("Message {$i}"));
    }

    $loaded = $memory->load('sess');

    expect($loaded)->toHaveCount(3)
        ->and(end($loaded)->content)->toBe('Message 4');
})->skip('Requires Mockery for Cache facade — integration test');

// ──────────────────────────────────────────────────────────────
// ChatResponse Value Object
// ──────────────────────────────────────────────────────────────

it('ChatResponse::ok() is not failed', function () {
    $r = ChatResponse::ok('Answer', 'sess-1', 'default', [], 10, 5, 0.001);
    expect($r->isFailed())->toBeFalse()
        ->and($r->answer)->toBe('Answer')
        ->and($r->hasSources())->toBeFalse();
});

it('ChatResponse::failed() exposes error details', function () {
    $r = ChatResponse::failed('sess-1', 'TIMEOUT', 'Request timed out.');
    expect($r->isFailed())->toBeTrue()
        ->and($r->errorCode)->toBe('TIMEOUT')
        ->and($r->errorMessage)->toBe('Request timed out.');
});

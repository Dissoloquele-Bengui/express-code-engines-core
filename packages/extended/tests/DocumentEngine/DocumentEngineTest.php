<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\DocumentEngine\Chunkers\ParagraphChunker;
use ExpressCodeEngines\Extended\DocumentEngine\DocumentChunkStore;
use ExpressCodeEngines\Extended\DocumentEngine\DocumentEngine;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentChunkerInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;
use ExpressCodeEngines\Shared\ValueObjects\DocumentResult;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function makeProcessor(array $extensions, ?string $text = 'Extracted text.', bool $returnsNull = false): DocumentProcessorInterface
{
    return new class ($extensions, $text, $returnsNull) implements DocumentProcessorInterface {
        public function __construct(
            private array   $ext,
            private ?string $text,
            private bool    $null,
        ) {}

        public function supportedExtensions(): array { return $this->ext; }
        public function extract(string $path): ?string
        {
            return $this->null ? null : $this->text;
        }
    };
}

function makeChunker(array $chunks = []): DocumentChunkerInterface
{
    return new class ($chunks) implements DocumentChunkerInterface {
        public function __construct(private array $chunks) {}
        public function chunk(string $text, string $documentId, array $meta = []): array
        {
            if (! empty($this->chunks)) return $this->chunks;
            return [new DocumentChunk('Chunk text', 0, $documentId, $meta)];
        }
    };
}

function makeStore(array &$saved = []): DocumentChunkStore
{
    return new class ($saved) extends DocumentChunkStore {
        public array $deleted = [];
        public function __construct(private array &$log) { /* no DB */ }
        public function saveMany(array $chunks): void { $this->log = array_merge($this->log, $chunks); }
        public function findByEmbedding(array $v, array $e, int $k): array { return []; }
        public function findByKeyword(string $q, array $e, int $k): array { return []; }
        public function deleteByDocumentId(string $id): bool { $this->deleted[] = $id; return true; }
    };
}

function makeAi(array $embedding = []): AiEngineInterface
{
    return new class ($embedding) implements AiEngineInterface {
        public function __construct(private array $emb) {}
        public function complete(\ExpressCodeEngines\Shared\DTOs\AiRequest $r): \ExpressCodeEngines\Shared\ValueObjects\AiResponse
        {
            return \ExpressCodeEngines\Shared\ValueObjects\AiResponse::ok('{}', 'test', 'model', 5, 5);
        }
        public function embed(string $text, string $provider = 'openai'): array { return $this->emb; }
    };
}

function makeDocEngine(
    array  $processors = [],
    ?DocumentChunkerInterface $chunker = null,
    ?DocumentChunkStore $store = null,
    ?AiEngineInterface $ai = null,
    array &$saved = [],
): DocumentEngine {
    return new DocumentEngine(
        processors: $processors ?: [makeProcessor(['txt'])],
        chunker:    $chunker    ?? makeChunker(),
        store:      $store      ?? makeStore($saved),
        ai:         $ai,
    );
}

// ──────────────────────────────────────────────────────────────
// DocumentEngine — process()
// ──────────────────────────────────────────────────────────────

it('processes a document and returns success result', function () {
    $engine = makeDocEngine();
    $result = $engine->process(new DocumentInput('/fake/file.txt'));

    expect($result->isFailed())->toBeFalse()
        ->and($result->documentId)->not->toBeNull()
        ->and($result->text)->toBe('Extracted text.')
        ->and($result->chunkCount)->toBe(1)
        ->and($result->embedded)->toBeFalse();
});

it('returns UNSUPPORTED_FORMAT for unknown file extension', function () {
    $engine = makeDocEngine(processors: [makeProcessor(['txt'])]);
    $result = $engine->process(new DocumentInput('/fake/file.xyz'));

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('UNSUPPORTED_FORMAT');
});

it('returns EXTRACTION_FAILED when processor returns null', function () {
    $engine = makeDocEngine(processors: [makeProcessor(['txt'], returnsNull: true)]);
    $result = $engine->process(new DocumentInput('/fake/file.txt'));

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('EXTRACTION_FAILED');
});

it('saves chunks to the store after processing', function () {
    $saved  = [];
    $engine = makeDocEngine(saved: $saved);

    $engine->process(new DocumentInput('/fake/file.txt'));

    expect($saved)->toHaveCount(1)
        ->and($saved[0])->toBeInstanceOf(DocumentChunk::class);
});

it('generates embeddings when AiEngine is provided', function () {
    $saved  = [];
    $ai     = makeAi([0.1, 0.2, 0.3]);
    $engine = makeDocEngine(ai: $ai, saved: $saved);

    $result = $engine->process(new DocumentInput('/fake/file.txt'));

    expect($result->embedded)->toBeTrue()
        ->and($saved[0]->embedding)->toBe([0.1, 0.2, 0.3]);
});

it('skips embedding gracefully when AiEngine returns empty array', function () {
    $saved  = [];
    $ai     = makeAi([]); // empty embedding
    $engine = makeDocEngine(ai: $ai, saved: $saved);

    $result = $engine->process(new DocumentInput('/fake/file.txt'));

    // embedded = true (AiEngine was called) but chunk has no embedding
    expect($result->embedded)->toBeTrue()
        ->and($saved[0]->embedding)->toBeNull();
});

it('deletes chunks for a document', function () {
    $saved = [];
    $store = makeStore($saved);

    $engine = makeDocEngine(store: $store);
    $result = $engine->delete('doc-123');

    expect($result)->toBeTrue()
        ->and($store->deleted)->toContain('doc-123');
});

it('attaches entity context to chunk meta', function () {
    $saved  = [];
    $engine = makeDocEngine(saved: $saved);

    $engine->process(new DocumentInput(
        source:      '/fake/file.txt',
        entityClass: 'App\Models\Order',
        entityId:    42,
    ));

    expect($saved[0]->meta['entity_class'])->toBe('App\Models\Order')
        ->and($saved[0]->meta['entity_id'])->toBe(42);
});

// ──────────────────────────────────────────────────────────────
// ParagraphChunker
// ──────────────────────────────────────────────────────────────

it('splits text into chunks by paragraph', function () {
    $chunker = new ParagraphChunker(targetSize: 50, maxSize: 200);
    $text    = "First paragraph with enough text to form a chunk.\n\nSecond paragraph with more content here.\n\nThird paragraph.";

    $chunks = $chunker->chunk($text, 'doc-1');

    expect($chunks)->not->toBeEmpty()
        ->and($chunks[0])->toBeInstanceOf(DocumentChunk::class)
        ->and($chunks[0]->documentId)->toBe('doc-1');
});

it('assigns sequential index to each chunk', function () {
    $chunker = new ParagraphChunker(targetSize: 10, maxSize: 50);
    $text    = "Para one.\n\nPara two.\n\nPara three.";

    $chunks = $chunker->chunk($text, 'doc-1');
    $indices = array_map(fn ($c) => $c->index, $chunks);

    expect($indices)->toBe(range(0, count($chunks) - 1));
});

it('returns empty array for blank text', function () {
    $chunker = new ParagraphChunker();
    expect($chunker->chunk('   ', 'doc-1'))->toBe([]);
});

it('passes meta to each chunk', function () {
    $chunker = new ParagraphChunker(targetSize: 10);
    $meta    = ['entity_class' => 'Order', 'entity_id' => 1];
    $chunks  = $chunker->chunk("Short text.", 'doc-1', $meta);

    expect($chunks[0]->meta)->toBe($meta);
});

// ──────────────────────────────────────────────────────────────
// DocumentResult Value Object
// ──────────────────────────────────────────────────────────────

it('DocumentResult::ok() is not failed', function () {
    $result = DocumentResult::ok('doc-1', 'text', 3, true);

    expect($result->isFailed())->toBeFalse()
        ->and($result->documentId)->toBe('doc-1')
        ->and($result->chunkCount)->toBe(3)
        ->and($result->embedded)->toBeTrue();
});

it('DocumentResult::failed() exposes error details', function () {
    $result = DocumentResult::failed('OCR_ERROR', 'Cannot read image.');

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('OCR_ERROR')
        ->and($result->errorMessage)->toBe('Cannot read image.');
});

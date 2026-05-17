"""
organize_engine.py — Reorganizador do monorepo ExpressCodeEngine
v3 — processa downloaded/ e uncategorized/ com suporte completo a todos os ficheiros.

Uso:
    python organize_engine.py --dry-run   # ver sem mover
    python organize_engine.py             # aplicar

Funcionalidades:
  - Remove sufixos de duplicado: "Foo (1).php" → "Foo.php"
  - Deduplicação por hash MD5 (conteúdo igual → apaga origem)
  - Conflito de conteúdo → renomeia para CONFLICT_filename.php
  - Cria pastas em falta automaticamente
  - Testes multi-engine em tests/Cross/ (decisão deliberada)
"""

import os
import re
import shutil
import hashlib
import argparse

ROOT_DIR     = os.path.dirname(os.path.abspath(__file__))
PACKAGES_DIR = os.path.join(ROOT_DIR, "packages")
DOCS_DIR     = os.path.join(ROOT_DIR, "docs")
SOURCES      = [
    os.path.join(ROOT_DIR, "downloaded"),
    os.path.join(ROOT_DIR, "uncategorized"),
]

# ============================================================================
# MAPEAMENTO  (ordem importa — mais específico primeiro)
# Prefixos especiais:
#   "docs:"  → DOCS_DIR/...
#   "root:"  → ROOT_DIR/...
#   sem pref → PACKAGES_DIR/...
# ============================================================================
FILE_MAPPING = [

    # ── DOCS / RAIZ ────────────────────────────────────────────────────────
    (r"^README\.md$",               "docs:README.md"),
    (r"^IMPLEMENTATION_GUIDE\.md$", "docs:IMPLEMENTATION_GUIDE.md"),
    (r"^EXPRESS_CODE_ENGINE\.md$",  "docs:EXPRESS_CODE_ENGINE.md"),
    (r"^RULES_CONFIGURATION\.md$",  "docs:RULES_CONFIGURATION.md"),
    (r"^CONFLICT_README\.md$",      "docs:CONFLICT_README.md"),
    (r"^phpstan\.neon\.dist$",      "root:phpstan.neon.dist"),
    (r"^phpstan\.neon$",            "root:phpstan.neon"),
    # extended.php é a config da package extended (não vai para root)
    (r"^extended\.php$",            "extended/config/extended.php"),

    # ── TESTES — Integração base ───────────────────────────────────────────
    (r"^EngineIntegrationTestCase\.php$",      "core/tests/Integration/EngineIntegrationTestCase.php"),
    (r"^ServiceProviderIntegrationTest\.php$", "core/tests/Integration/ServiceProviderIntegrationTest.php"),
    (r"^ExtendedServiceProviderTest\.php$",    "extended/tests/Integration/ExtendedServiceProviderTest.php"),
    (r"^IntegrationTest\.php$",                "extended/tests/NotificationEngine/IntegrationTest.php"),

    # ── TESTES — Cross (multi-engine) — mantidos juntos por decisão ───────
    (r"^PendingItemsTest\.php$", "extended/tests/Cross/PendingItemsTest.php"),
    (r"^RoadmapTest\.php$",      "extended/tests/Cross/RoadmapTest.php"),

    # ── TESTES — Core ──────────────────────────────────────────────────────
    (r"^ConstraintEngineTest\.php$",   "core/tests/ConstraintEngine/ConstraintEngineTest.php"),
    (r"^BusinessRuleEngineTest\.php$", "core/tests/BusinessRuleEngine/BusinessRuleEngineTest.php"),
    (r"^ComputationEngineTest\.php$",  "core/tests/ComputationEngine/ComputationEngineTest.php"),
    (r"^WorkflowEngineTest\.php$",     "core/tests/WorkflowEngine/WorkflowEngineTest.php"),

    # ── TESTES — Extended ──────────────────────────────────────────────────
    (r"^ReactionEngineTest\.php$",     "extended/tests/ReactionEngine/ReactionEngineTest.php"),
    (r"^NotificationEngineTest\.php$", "extended/tests/NotificationEngine/NotificationEngineTest.php"),
    (r"^DynamicPolicyEngineTest\.php$","extended/tests/DynamicPolicyEngine/DynamicPolicyEngineTest.php"),
    (r"^SearchEngineTest\.php$",       "extended/tests/SearchEngine/SearchEngineTest.php"),
    (r"^IntegrationEngineTest\.php$",  "extended/tests/IntegrationEngine/IntegrationEngineTest.php"),
    (r"^OrchestrationEngineTest\.php$","extended/tests/OrchestrationEngine/OrchestrationEngineTest.php"),
    (r"^AiEngineTest\.php$",           "extended/tests/AiEngine/AiEngineTest.php"),
    (r"^ProvidersTest\.php$",          "extended/tests/AiEngine/ProvidersTest.php"),
    (r"^DocumentEngineTest\.php$",     "extended/tests/DocumentEngine/DocumentEngineTest.php"),
    (r"^ChatEngineTest\.php$",         "extended/tests/ChatEngine/ChatEngineTest.php"),

    # ── SHARED — Contracts ─────────────────────────────────────────────────
    (r"^AiContracts\.php$",                   "shared/src/Contracts/AiContracts.php"),
    (r"^AiPhaseContracts\.php$",              "shared/src/Contracts/AiPhaseContracts.php"),
    (r"^BusinessRuleEngineInterface\.php$",   "shared/src/Contracts/BusinessRuleEngineInterface.php"),
    (r"^ComputationEngineInterface\.php$",    "shared/src/Contracts/ComputationEngineInterface.php"),
    (r"^ConstraintCheckerInterface\.php$",    "shared/src/Contracts/ConstraintCheckerInterface.php"),
    (r"^ConstraintEngineInterface\.php$",     "shared/src/Contracts/ConstraintEngineInterface.php"),
    (r"^DynamicPolicyEngineInterface\.php$",  "shared/src/Contracts/DynamicPolicyEngineInterface.php"),
    (r"^IntegrationContracts\.php$",          "shared/src/Contracts/IntegrationContracts.php"),
    (r"^OrchestrationContracts\.php$",        "shared/src/Contracts/OrchestrationContracts.php"),
    (r"^ReactionNotificationContracts\.php$", "shared/src/Contracts/ReactionNotificationContracts.php"),
    (r"^RuleEvaluatorInterface\.php$",        "shared/src/Contracts/RuleEvaluatorInterface.php"),
    (r"^SearchContracts\.php$",               "shared/src/Contracts/SearchContracts.php"),
    (r"^StepHandlerInterface\.php$",          "shared/src/Contracts/StepHandlerInterface.php"),
    (r"^WebhookContracts\.php$",              "shared/src/Contracts/WebhookContracts.php"),
    (r"^WorkflowEngineInterface\.php$",       "shared/src/Contracts/WorkflowEngineInterface.php"),
    (r"^WorkflowGuardInterface\.php$",        "shared/src/Contracts/WorkflowGuardInterface.php"),
    # Fallback genérico
    (r".*Interface\.php$",   "shared/src/Contracts"),
    (r".*Contracts?\.php$",  "shared/src/Contracts"),

    # ── SHARED — DTOs ──────────────────────────────────────────────────────
    (r"^AiChatDocumentDTO\.php$",       "shared/src/DTOs/AiChatDocumentDTO.php"),
    (r"^AiPhaseDTO\.php$",              "shared/src/DTOs/AiPhaseDTO.php"),
    (r"^BusinessRuleDTO\.php$",         "shared/src/DTOs/BusinessRuleDTO.php"),
    (r"^ComputationWorkflowDTO\.php$",  "shared/src/DTOs/ComputationWorkflowDTO.php"),
    (r"^ConstraintDTO\.php$",           "shared/src/DTOs/ConstraintDTO.php"),
    (r"^IntegrationDTO\.php$",          "shared/src/DTOs/IntegrationDTO.php"),
    (r"^OrchestrationDTO\.php$",        "shared/src/DTOs/OrchestrationDTO.php"),
    (r"^PolicyDTO\.php$",               "shared/src/DTOs/PolicyDTO.php"),
    (r"^ReactionNotificationDTO\.php$", "shared/src/DTOs/ReactionNotificationDTO.php"),
    (r"^SearchDTO\.php$",               "shared/src/DTOs/SearchDTO.php"),
    (r"^WorkflowDTO\.php$",             "shared/src/DTOs/WorkflowDTO.php"),
    (r".*DTO\.php$",                    "shared/src/DTOs"),  # fallback

    # ── SHARED — ValueObjects ──────────────────────────────────────────────
    (r"^Responses\.php$",                     "shared/src/ValueObjects/Responses.php"),
    (r"^AiResponses\.php$",                   "shared/src/ValueObjects/AiResponses.php"),
    (r"^ReactionNotificationResponses\.php$", "shared/src/ValueObjects/ReactionNotificationResponses.php"),
    (r"^GenericDatabaseNotification\.php$",   "extended/src/Notifications/GenericDatabaseNotification.php"),

    # ── CORE — Service Provider / Validator ───────────────────────────────
    (r"^EngineServiceProvider\.php$", "core/src/EngineServiceProvider.php"),
    (r"^EngineConfigValidator\.php$", "core/src/EngineConfigValidator.php"),

    # ── CORE — ConstraintEngine ────────────────────────────────────────────
    (r"^ConstraintEngine\.php$",  "core/src/ConstraintEngine/ConstraintEngine.php"),
    (r"^UniquenessChecker\.php$", "core/src/ConstraintEngine/Checkers/UniquenessChecker.php"),
    (r"^OverlapChecker\.php$",    "core/src/ConstraintEngine/Checkers/OverlapChecker.php"),
    (r"^LimitChecker\.php$",      "core/src/ConstraintEngine/Checkers/LimitChecker.php"),

    # ── CORE — BusinessRuleEngine ──────────────────────────────────────────
    (r"^BusinessRuleEngine\.php$",      "core/src/BusinessRuleEngine/BusinessRuleEngine.php"),
    (r"^RuleEvaluatorRegistry\.php$",   "core/src/BusinessRuleEngine/RuleEvaluatorRegistry.php"),
    (r"^ComparisonEvaluator\.php$",     "core/src/BusinessRuleEngine/Evaluators/ComparisonEvaluator.php"),
    (r"^RangeEvaluator\.php$",          "core/src/BusinessRuleEngine/Evaluators/RangeEvaluator.php"),
    (r"^InListEvaluator\.php$",         "core/src/BusinessRuleEngine/Evaluators/InListEvaluator.php"),
    (r"^RegexEvaluator\.php$",          "core/src/BusinessRuleEngine/Evaluators/RegexEvaluator.php"),
    (r"^CustomCallableEvaluator\.php$", "core/src/BusinessRuleEngine/Evaluators/CustomCallableEvaluator.php"),
    (r"^ConditionEvaluator\.php$",      "core/src/BusinessRuleEngine/Evaluators/ConditionEvaluator.php"),
    # PolicyConditionEvaluator pertence à DynamicPolicyEngine, NÃO ao BRE
    (r"^PolicyConditionEvaluator\.php$","extended/src/DynamicPolicyEngine/Conditions/PolicyConditionEvaluator.php"),

    # ── CORE — ComputationEngine ───────────────────────────────────────────
    (r"^ComputationEngine\.php$",   "core/src/ComputationEngine/ComputationEngine.php"),
    (r"^OperationRegistry\.php$",   "core/src/ComputationEngine/OperationRegistry.php"),
    (r"^BaseOperation\.php$",       "core/src/ComputationEngine/Operations/BaseOperation.php"),
    (r"^AddOperation\.php$",        "core/src/ComputationEngine/Operations/AddOperation.php"),
    (r"^SubtractOperation\.php$",   "core/src/ComputationEngine/Operations/SubtractOperation.php"),
    (r"^MultiplyOperation\.php$",   "core/src/ComputationEngine/Operations/MultiplyOperation.php"),
    (r"^DivideOperation\.php$",     "core/src/ComputationEngine/Operations/DivideOperation.php"),
    (r"^SumOperation\.php$",        "core/src/ComputationEngine/Operations/SumOperation.php"),
    (r"^AverageOperation\.php$",    "core/src/ComputationEngine/Operations/AverageOperation.php"),
    (r"^MinOperation\.php$",        "core/src/ComputationEngine/Operations/MinOperation.php"),
    (r"^MaxOperation\.php$",        "core/src/ComputationEngine/Operations/MaxOperation.php"),
    (r"^RoundOperation\.php$",      "core/src/ComputationEngine/Operations/RoundOperation.php"),
    (r"^PercentageOperation\.php$", "core/src/ComputationEngine/Operations/PercentageOperation.php"),
    (r"^AbsOperation\.php$",        "core/src/ComputationEngine/Operations/AbsOperation.php"),
    (r"^ClampOperation\.php$",      "core/src/ComputationEngine/Operations/ClampOperation.php"),
    (r"^IifOperation\.php$",        "core/src/ComputationEngine/Operations/IifOperation.php"),

    # ── CORE — WorkflowEngine ──────────────────────────────────────────────
    (r"^WorkflowEngine\.php$",  "core/src/WorkflowEngine/WorkflowEngine.php"),
    (r"^RoleGuard\.php$",       "core/src/WorkflowEngine/Guards/RoleGuard.php"),
    (r"^FieldGuard\.php$",      "core/src/WorkflowEngine/Guards/FieldGuard.php"),
    (r"^CallableGuard\.php$",   "core/src/WorkflowEngine/Guards/CallableGuard.php"),

    # ── CORE — Helpers / Examples / Console ───────────────────────────────
    (r"^ContextBuilder\.php$",     "core/src/Helpers/ContextBuilder.php"),
    (r"^CreateOrderAction\.php$",  "core/src/Examples/CreateOrderAction.php"),
    (r"^EnginesGraphCommand\.php$","core/src/Console/EnginesGraphCommand.php"),

    # ── EXTENDED — Service Provider ────────────────────────────────────────
    (r"^ExtendedServiceProvider\.php$", "extended/src/ExtendedServiceProvider.php"),

    # ── EXTENDED — ReactionEngine ──────────────────────────────────────────
    (r"^ReactionEngine\.php$",          "extended/src/ReactionEngine/ReactionEngine.php"),
    (r"^ReactionHandlerRegistry\.php$", "extended/src/ReactionEngine/ReactionHandlerRegistry.php"),
    (r"^NotifyHandler\.php$",           "extended/src/ReactionEngine/Handlers/NotifyHandler.php"),
    (r"^DispatchJobHandler\.php$",      "extended/src/ReactionEngine/Handlers/DispatchJobHandler.php"),
    (r"^UpdateFieldHandler\.php$",      "extended/src/ReactionEngine/Handlers/UpdateFieldHandler.php"),

    # ── EXTENDED — NotificationEngine ─────────────────────────────────────
    (r"^NotificationEngine\.php$",      "extended/src/NotificationEngine/NotificationEngine.php"),
    (r"^TemplateRegistry\.php$",        "extended/src/NotificationEngine/TemplateRegistry.php"),
    (r"^TemplateRenderer\.php$",        "extended/src/NotificationEngine/Renderers/TemplateRenderer.php"),
    (r"^NotificationRateLimiter\.php$", "extended/src/NotificationEngine/RateLimiting/NotificationRateLimiter.php"),
    (r"^MailChannel\.php$",             "extended/src/NotificationEngine/Channels/MailChannel.php"),
    (r"^DatabaseChannel\.php$",         "extended/src/NotificationEngine/Channels/DatabaseChannel.php"),
    (r"^SlackChannel\.php$",            "extended/src/NotificationEngine/Channels/SlackChannel.php"),
    (r"^SmsChannel\.php$",              "extended/src/NotificationEngine/Channels/SmsChannel.php"),
    (r"^PushChannel\.php$",             "extended/src/NotificationEngine/Channels/PushChannel.php"),

    # ── EXTENDED — Jobs ────────────────────────────────────────────────────
    (r"^SendNotificationJob\.php$",   "extended/src/Jobs/SendNotificationJob.php"),
    (r"^ExecuteIntegrationJob\.php$", "extended/src/Jobs/ExecuteIntegrationJob.php"),

    # ── EXTENDED — DynamicPolicyEngine ────────────────────────────────────
    (r"^DynamicPolicyEngine\.php$", "extended/src/DynamicPolicyEngine/DynamicPolicyEngine.php"),

    # ── EXTENDED — SearchEngine ────────────────────────────────────────────
    (r"^SearchEngine\.php$",      "extended/src/SearchEngine/SearchEngine.php"),
    (r"^DatabaseDriver\.php$",    "extended/src/SearchEngine/Drivers/DatabaseDriver.php"),
    (r"^MeilisearchDriver\.php$", "extended/src/SearchEngine/Drivers/MeilisearchDriver.php"),
    (r"^AlgoliaDriver\.php$",     "extended/src/SearchEngine/Drivers/AlgoliaDriver.php"),
    # NullDriver: lógica especial → ver get_destination()

    # ── EXTENDED — IntegrationEngine ──────────────────────────────────────
    (r"^IntegrationEngine\.php$",   "extended/src/IntegrationEngine/IntegrationEngine.php"),
    (r"^IntegrationRegistry\.php$", "extended/src/IntegrationEngine/IntegrationRegistry.php"),
    (r"^PayloadMapper\.php$",       "extended/src/IntegrationEngine/Http/PayloadMapper.php"),
    (r"^WebhookProcessor\.php$",    "extended/src/IntegrationEngine/Webhooks/WebhookProcessor.php"),

    # ── EXTENDED — OrchestrationEngine ────────────────────────────────────
    (r"^OrchestrationEngine\.php$", "extended/src/OrchestrationEngine/OrchestrationEngine.php"),

    # ── EXTENDED — AiEngine ────────────────────────────────────────────────
    (r"^AiEngine\.php$",              "extended/src/AiEngine/AiEngine.php"),
    (r"^AiProviderRegistry\.php$",    "extended/src/AiEngine/AiProviderRegistry.php"),
    (r"^CachedEmbeddingEngine\.php$", "extended/src/AiEngine/CachedEmbeddingEngine.php"),
    (r"^OpenAiProvider\.php$",        "extended/src/AiEngine/Providers/OpenAiProvider.php"),
    (r"^AnthropicProvider\.php$",     "extended/src/AiEngine/Providers/AnthropicProvider.php"),
    (r"^GroqProvider\.php$",          "extended/src/AiEngine/Providers/GroqProvider.php"),
    (r"^OpenRouterProvider\.php$",    "extended/src/AiEngine/Providers/OpenRouterProvider.php"),
    (r"^OllamaProvider\.php$",        "extended/src/AiEngine/Providers/OllamaProvider.php"),
    (r"^HuggingFaceProvider\.php$",   "extended/src/AiEngine/Providers/HuggingFaceProvider.php"),
    (r"^NullAiProvider\.php$",        "extended/src/AiEngine/Providers/NullAiProvider.php"),

    # ── EXTENDED — DocumentEngine ──────────────────────────────────────────
    (r"^DocumentEngine\.php$",         "extended/src/DocumentEngine/DocumentEngine.php"),
    (r"^DocumentChunkStore\.php$",     "extended/src/DocumentEngine/DocumentChunkStore.php"),
    (r"^MySqlVectorChunkStore\.php$",  "extended/src/DocumentEngine/MySqlVectorChunkStore.php"),
    (r"^PgvectorChunkStore\.php$",     "extended/src/DocumentEngine/PgvectorChunkStore.php"),
    (r"^ParagraphChunker\.php$",       "extended/src/DocumentEngine/Chunkers/ParagraphChunker.php"),
    (r"^PdfProcessor\.php$",           "extended/src/DocumentEngine/Processors/PdfProcessor.php"),
    (r"^DocxProcessor\.php$",          "extended/src/DocumentEngine/Processors/DocxProcessor.php"),
    (r"^HtmlProcessor\.php$",          "extended/src/DocumentEngine/Processors/HtmlProcessor.php"),
    (r"^MarkdownProcessor\.php$",      "extended/src/DocumentEngine/Processors/MarkdownProcessor.php"),
    (r"^PlainTextProcessor\.php$",     "extended/src/DocumentEngine/Processors/PlainTextProcessor.php"),
    (r"^TextProcessor\.php$",          "extended/src/DocumentEngine/Processors/TextProcessor.php"),

    # ── EXTENDED — ChatEngine ──────────────────────────────────────────────
    (r"^ChatEngine\.php$",        "extended/src/ChatEngine/ChatEngine.php"),
    (r"^PersonaRegistry\.php$",   "extended/src/ChatEngine/PersonaRegistry.php"),
    (r"^CacheMemory\.php$",       "extended/src/ChatEngine/Memory/CacheMemory.php"),
    (r"^CacheSessionStore\.php$", "extended/src/ChatEngine/Memory/CacheSessionStore.php"),
]

# ============================================================================
# Ficheiros a ignorar completamente
# ============================================================================
IGNORE_PATTERNS = [
    r"^composer(\s*\(\d+\))?\.json$",
    r"^env(\s*\(\d+\))?\.example$",
    r"^\.gitkeep$",
    r"^\.DS_Store$",
    r"^Thumbs\.db$",
]

# ============================================================================
# Utilitários
# ============================================================================

def normalize_filename(filename: str) -> str:
    """Remove sufixos de duplicado: 'Foo (1).php' → 'Foo.php'"""
    return re.sub(r"\s*\(\d+\)(\.[^.]+)$", r"\1", filename)


def file_hash(path: str) -> str:
    h = hashlib.md5()
    with open(path, "rb") as f:
        h.update(f.read())
    return h.hexdigest()


def should_ignore(filename: str) -> bool:
    for pattern in IGNORE_PATTERNS:
        if re.match(pattern, filename, re.IGNORECASE):
            return True
    return False


def resolve_null_driver(source_path: str) -> str:
    """NullDriver.php pode ser SearchEngine ou AiEngine — decide pelo conteúdo."""
    try:
        with open(source_path, "r", encoding="utf-8", errors="ignore") as f:
            content = f.read()
        if "SearchDriverInterface" in content or "SearchResult" in content:
            return "extended/src/SearchEngine/Drivers/NullDriver.php"
        if "AiProviderInterface" in content:
            return "extended/src/AiEngine/Providers/NullDriver.php"
    except Exception:
        pass
    return "extended/src/SearchEngine/Drivers/NullDriver.php"


def get_destination(filename: str, source_path: str) -> str | None:
    """Retorna o destino mapeado, ou None se não houver mapeamento."""
    if re.match(r"^NullDriver\.php$", filename, re.IGNORECASE):
        return resolve_null_driver(source_path)
    for pattern, dest in FILE_MAPPING:
        if re.match(pattern, filename, re.IGNORECASE):
            return dest
    return None


def resolve_absolute(dest: str) -> str:
    """Converte destino relativo em caminho absoluto."""
    if dest.startswith("docs:"):
        return os.path.join(DOCS_DIR, dest[5:])
    if dest.startswith("root:"):
        return os.path.join(ROOT_DIR, dest[5:])
    if "." in os.path.basename(dest):
        return os.path.join(PACKAGES_DIR, dest)
    return os.path.join(PACKAGES_DIR, dest)


# ============================================================================
# Main
# ============================================================================

def main():
    parser = argparse.ArgumentParser(description="Organiza ficheiros do monorepo ExpressCodeEngine.")
    parser.add_argument("--dry-run", action="store_true",
                        help="Mostra o que seria feito sem mover nenhum ficheiro.")
    args = parser.parse_args()

    dry = args.dry_run
    if dry:
        print("══ DRY RUN — nenhum ficheiro será movido ══\n")

    stats  = {"moved": 0, "skipped_duplicate": 0, "ignored": 0, "review": 0, "errors": 0}
    review = []

    for source_dir in SOURCES:
        if not os.path.exists(source_dir):
            print(f"[SKIP] Pasta não encontrada: {source_dir}")
            continue

        print(f"\n── A processar: {os.path.relpath(source_dir, ROOT_DIR)} ──")

        for filename in sorted(os.listdir(source_dir)):
            source_path = os.path.join(source_dir, filename)
            if os.path.isdir(source_path):
                continue

            # 1. Ignorar?
            if should_ignore(filename):
                print(f"  [IGNORE]   {filename}")
                stats["ignored"] += 1
                continue

            # 2. Normalizar nome (remove "(N)")
            clean_name     = normalize_filename(filename)
            was_normalized = clean_name != filename

            # 3. Resolver destino
            dest_rel = get_destination(clean_name, source_path)
            if dest_rel is None:
                print(f"  [REVIEW]   {filename}  ← sem mapeamento")
                review.append(f"{os.path.relpath(source_dir, ROOT_DIR)}/{filename}")
                stats["review"] += 1
                continue

            dest_abs = resolve_absolute(dest_rel)
            # Se destino é pasta (sem extensão), adiciona o nome limpo
            if not os.path.splitext(os.path.basename(dest_abs))[1]:
                dest_abs = os.path.join(dest_abs, clean_name)

            # 4. Deduplicação por hash
            if os.path.exists(dest_abs):
                if file_hash(dest_abs) == file_hash(source_path):
                    action = "SERIA APAGADO" if dry else "APAGADO"
                    print(f"  [DUP=]     {filename} ≡ {os.path.relpath(dest_abs, ROOT_DIR)}  ({action})")
                    if not dry:
                        os.remove(source_path)
                    stats["skipped_duplicate"] += 1
                else:
                    conflict = f"CONFLICT_{filename}"
                    print(f"  [CONFLICT] {filename} ≠ {os.path.relpath(dest_abs, ROOT_DIR)}  → {conflict}")
                    if not dry:
                        os.rename(source_path, os.path.join(source_dir, conflict))
                    review.append(f"{os.path.relpath(source_dir, ROOT_DIR)}/{conflict}")
                    stats["review"] += 1
                continue

            # 5. Mover
            label = "WOULD MOVE" if dry else "MOVED    "
            note  = f"  (era: {filename})" if was_normalized else ""
            print(f"  [{label}]  {filename} → {os.path.relpath(dest_abs, ROOT_DIR)}{note}")

            if not dry:
                try:
                    os.makedirs(os.path.dirname(dest_abs), exist_ok=True)
                    shutil.move(source_path, dest_abs)
                    stats["moved"] += 1
                except Exception as e:
                    print(f"  [ERROR]    {filename}: {e}")
                    stats["errors"] += 1
            else:
                stats["moved"] += 1

    # Resumo
    print("\n" + "═" * 62)
    print(f"  Movidos:              {stats['moved']}")
    print(f"  Duplicados removidos: {stats['skipped_duplicate']}")
    print(f"  Ignorados:            {stats['ignored']}")
    print(f"  Para revisão:         {stats['review']}")
    print(f"  Erros:                {stats['errors']}")

    if review:
        print("\n  Ficheiros para revisão manual:")
        for f in review:
            print(f"    {f}")

    if dry:
        print("\n  Execute sem --dry-run para aplicar as alterações.")
    print("═" * 62)


if __name__ == "__main__":
    main()
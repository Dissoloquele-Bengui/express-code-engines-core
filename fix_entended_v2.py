"""fix_extended_v2.py — corrige facade Log, DTOs corrompidos, e NotificationResult nos testes"""
import os
import re
import glob

# ─── Fix 1: SearchEngine e ReactionEngine — error_log com arrays ──────────
# error_log() só aceita string, não array. Substituir por error_log com json_encode

for path in [
    'packages/extended/src/SearchEngine/SearchEngine.php',
    'packages/extended/src/ReactionEngine/ReactionEngine.php',
]:
    if not os.path.exists(path):
        print(f'SKIP: {path} not found')
        continue
    text = open(path, encoding='utf-8').read()
    changed = False

    # Padrão: error_log('message', [\n  context\n]);
    # Substituir por: error_log('message ' . json_encode([context]));
    # Mais simples: substituir error_log(msg, [ctx]) por um bloco seguro

    # Remove error_log calls with array second arg — replace with try/catch silencer
    # Mais prático: substituir toda a chamada error_log que tem array por nada (silent)
    # Para engines unitárias, o logging não é necessário nos testes

    # Abordagem: substituir error_log('msg', [...]) por error_log('msg: ' . json_encode([...]))
    # Mas é complexo com regex. Abordagem mais simples: substituir error_log por uma função interna

    if 'private function logSafe(' not in text:
        # Adicionar método helper
        helper = '''
    private function logSafe(string $message, array $context = []): void
    {
        $msg = $message;
        if ($context) {
            $msg .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        if (function_exists('logger')) {
            logger()->error($msg);
        }
    }
'''
        last_brace = text.rfind('}')
        text = text[:last_brace] + helper + '}\n'
        changed = True

    # Substituir error_log( por $this->logSafe(
    if 'error_log(' in text:
        text = text.replace('error_log(', '$this->logSafe(')
        changed = True

    if changed:
        open(path, 'w', encoding='utf-8').write(text)
        print(f'Fixed: {os.path.basename(path)} (error_log -> logSafe)')

# ─── Fix 2: DTOs corrompidos pelo split ──────────────────────────────────
# NotificationRequest.php, NotificationTemplate.php, ReactionDefinition.php
# podem estar corrompidos (contêm corpo de outra classe)

corrupted_dtos = [
    'packages/shared/src/DTOs/NotificationRequest.php',
    'packages/shared/src/DTOs/NotificationTemplate.php',
    'packages/shared/src/DTOs/ReactionDefinition.php',
]

for path in corrupted_dtos:
    if not os.path.exists(path):
        continue
    text = open(path, encoding='utf-8').read()
    # Verificar se tem declaração de classe
    if 'final class ' not in text and 'class ' not in text:
        print(f'CORRUPTED: {path} — missing class declaration')
    # Verificar se começa com propriedades soltas (sinal de corrupção)
    lines = text.strip().split('\n')
    for line in lines[3:7]:  # skip <?php, declare, namespace
        stripped = line.strip()
        if stripped.startswith('public readonly') and 'class' not in text[:text.index(stripped)].split('final')[-1]:
            print(f'CORRUPTED: {path} — has naked properties without class')
            break

# ─── Fix 3: NotificationResult testes — failed() foi renomeado para isFailed() ──
# Os testes chamam ->failed() mas agora é ->isFailed()

for path in glob.glob('packages/extended/tests/**/*.php', recursive=True):
    text = open(path, encoding='utf-8').read()
    changed = False

    # Substituir chamadas a ->failed() por ->isFailed() nos ValueObjects
    # Mas cuidado para não apanhar static::failed() ou HandlerResult::failed()
    # Só queremos instância: $result->failed()
    if '->failed()' in text:
        text = text.replace('->failed()', '->isFailed()')
        changed = True
        print(f'Fixed: {os.path.basename(path)} (->failed() -> ->isFailed())')

    if changed:
        open(path, 'w', encoding='utf-8').write(text)

# ─── Fix 4: SearchResult, IntegrationResult etc — mesmos métodos duplicados ──

for path in glob.glob('packages/shared/src/**/*.php', recursive=True):
    text = open(path, encoding='utf-8').read()
    if 'static function failed(' in text and 'public function failed(): bool' in text:
        text = text.replace('public function failed(): bool', 'public function isFailed(): bool')
        open(path, 'w', encoding='utf-8').write(text)
        print(f'Fixed: {os.path.basename(path)} (duplicate failed())')

print('\nDone. Run:')
print('  composer dump-autoload')
print('  .\\vendor\\bin\\pest.bat packages/extended/tests/ReactionEngine packages/extended/tests/NotificationEngine packages/extended/tests/SearchEngine packages/extended/tests/OrchestrationEngine packages/extended/tests/DynamicPolicyEngine --no-coverage')
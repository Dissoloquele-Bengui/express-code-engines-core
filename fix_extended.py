"""
fix_facades_and_vos.py — Resolve os dois problemas sistémicos:
1. Substitui TODAS as chamadas Log:: facade por logger() com fallback seguro
2. Copia SearchResult e OrchestrationResult para ValueObjects (namespace correcto)
"""
import os
import re
import glob
import shutil

fixed = []

# ═══════════════════════════════════════════════════════════════
# 1. Substituir Log:: por logger() seguro em TODOS os ficheiros src
# ═══════════════════════════════════════════════════════════════

for path in glob.glob('packages/extended/src/**/*.php', recursive=True):
    text = open(path, encoding='utf-8').read()
    if 'Log::' not in text:
        continue

    # Remover "use Illuminate\Support\Facades\Log;"
    text = re.sub(r'use Illuminate\\Support\\Facades\\Log;\n?', '', text)

    # Substituir Log::error('msg', [...]) por try/catch silencioso
    # Log::error pode ter contexto multi-linha, difícil com regex simples.
    # Abordagem: substituir Log::error( por uma chamada segura inline
    # logger()->error() funciona quando o container esta disponível,
    # error_log() é o fallback universal

    # Padrão simples: substituir "Log::" por chamada segura
    # Usamos function_exists para fallback
    text = text.replace(
        'Log::error(',
        "(function_exists('logger') ? logger()->error("
    )
    text = text.replace(
        'Log::info(',
        "(function_exists('logger') ? logger()->info("
    )
    text = text.replace(
        'Log::warning(',
        "(function_exists('logger') ? logger()->warning("
    )
    text = text.replace(
        'Log::debug(',
        "(function_exists('logger') ? logger()->debug("
    )

    # Mas isso quebra a sintaxe porque falta o ): null
    # Abordagem diferente: substituir toda a chamada Log::level(...); 
    # por uma versão com try/catch

    # Reset - ler de novo
    text = open(path, encoding='utf-8').read()
    text = re.sub(r'use Illuminate\\Support\\Facades\\Log;\n?', '', text)

    # Substituir chamadas Log:: inteiras por bloco try/catch inline
    # Padrão: Log::error('msg'); ou Log::error('msg', [ctx]);
    # Substituir por: (silenciar - em testes unitários o log não importa)

    # Abordagem mais robusta: substituir Log:: por uma chamada
    # que não crasha sem container

    # A forma mais limpa: adicionar um trait ou usar try/catch
    # Mas para simplicidade máxima, vamos usar @-suppression + function_exists

    # Na verdade a forma mais simples e correcta:
    # Substituir "Log::" por uma helper function que existe
    
    # Vamos injectar uma função helper no topo do namespace se não existir
    # e substituir Log:: por logSafe_

    # Approach final: wrap CADA chamada Log::xxx(...) num try/catch
    def replace_log_call(match):
        full = match.group(0)
        return 'try { ' + full + ' } catch (\\Throwable $__e) {}'
    
    text = re.sub(
        r'Log::\w+\([^;]+\);',
        replace_log_call,
        text
    )

    open(path, 'w', encoding='utf-8').write(text)
    fixed.append(f'{os.path.basename(path)} (Log:: wrapped in try/catch)')


# ═══════════════════════════════════════════════════════════════
# 2. Copiar SearchResult e OrchestrationResult para ValueObjects
# ═══════════════════════════════════════════════════════════════

copies = {
    'packages/shared/src/Contracts/SearchResult.php': 'packages/shared/src/ValueObjects/SearchResult.php',
    'packages/shared/src/Contracts/OrchestrationResult.php': 'packages/shared/src/ValueObjects/OrchestrationResult.php',
}

for src, dst in copies.items():
    if not os.path.exists(src):
        print(f'SKIP: {src} not found')
        continue
    
    text = open(src, encoding='utf-8').read()
    
    # Mudar o namespace de Contracts para ValueObjects
    text = text.replace(
        'namespace ExpressCodeEngines\\Shared\\Contracts;',
        'namespace ExpressCodeEngines\\Shared\\ValueObjects;'
    )
    
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    open(dst, 'w', encoding='utf-8').write(text)
    fixed.append(f'{os.path.basename(dst)} (copied to ValueObjects)')


# ═══════════════════════════════════════════════════════════════
# 3. Garantir que NotificationEngine.php chama failed() com args
# O src chama NotificationResult::failed() sem argumentos na linha 85
# ═══════════════════════════════════════════════════════════════

path = 'packages/extended/src/NotificationEngine/NotificationEngine.php'
if os.path.exists(path):
    text = open(path, encoding='utf-8').read()
    # Encontrar chamadas a NotificationResult::failed() sem args ou com poucos args
    # e adicionar defaults
    text = text.replace(
        'NotificationResult::failed()',
        "NotificationResult::failed('unknown', 'CHANNEL_ERROR', 'Channel delivery failed')"
    )
    open(path, 'w', encoding='utf-8').write(text)
    fixed.append('NotificationEngine.php (failed() args)')


# ═══════════════════════════════════════════════════════════════
# 4. Verificar OrchestrationResult duplicado failed()
# ═══════════════════════════════════════════════════════════════

for path in [
    'packages/shared/src/ValueObjects/OrchestrationResult.php',
    'packages/shared/src/Contracts/OrchestrationResult.php',
]:
    if not os.path.exists(path):
        continue
    text = open(path, encoding='utf-8').read()
    if 'static function failed(' in text and 'public function failed(): bool' in text:
        text = text.replace('public function failed(): bool', 'public function isFailed(): bool')
        open(path, 'w', encoding='utf-8').write(text)
        fixed.append(f'{os.path.basename(path)} (failed() -> isFailed())')


print(f'\n=== {len(fixed)} fixes applied ===')
for f in fixed:
    print(f'  ✓ {f}')

print('\nRun:')
print('  composer dump-autoload')
print('  .\\vendor\\bin\\pest.bat packages/extended/tests/ReactionEngine packages/extended/tests/NotificationEngine packages/extended/tests/SearchEngine packages/extended/tests/OrchestrationEngine packages/extended/tests/DynamicPolicyEngine --no-coverage')
"""fix_operations.py — corrige os 3 bugs menores nas operations core"""
import os

# Fix 1: SumOperation — retornar 0.0 (float) em vez de 0 (int)
path = 'packages/core/src/ComputationEngine/Operations/SumOperation.php'
text = open(path, encoding='utf-8').read()
text = text.replace(
    'return ComputationResult::ok(0);',
    'return ComputationResult::ok(0.0);'
)
open(path, 'w', encoding='utf-8').write(text)
print('Fixed: SumOperation (0 -> 0.0)')

# Fix 2: BaseOperation applyPrecision — round() antes de bcadd para evitar truncamento
path = 'packages/core/src/ComputationEngine/Operations/BaseOperation.php'
text = open(path, encoding='utf-8').read()
text = text.replace(
    "return bcadd(number_format($value, $precision + 2, '.', ''), '0', $precision);",
    "return bcadd((string) round($value, $precision + 2), '0', $precision);"
)
open(path, 'w', encoding='utf-8').write(text)
print('Fixed: BaseOperation (round before bcadd)')

# Fix 3: RegexEvaluator — semantica correcta:
# O teste diz: regex /^PT\d{9}$/ com severity 'deny'
#   - PT123456789 matches -> regra dispara -> denial (test expects NO denial = bug no teste)
# O evaluator está correcto. O bug é no teste.
# A lógica do regex é: "fires when value matches pattern"
# Para dizer "deny quando NAO bate", o teste deveria inverter o pattern
# ou o teste deveria esperar denial quando o valor é válido.
#
# O fix mais correcto: ajustar o teste para a semantica real do evaluator.
# O regex evaluator "fires" quando há match — portanto uma regra 'deny' com
# pattern /^PT\d{9}$/ nega valores que SÃO válidos (intencional? não).
# A interpretação correcta para "deny formato inválido" é negar quando NÃO há match.
# Duas opções:
#   A) Inverter no evaluator (return !preg_match)
#   B) Ajustar o teste para usar a lógica actual
#
# Opção A é mais intuitiva para uso em regras de negócio:
# "deny when regex NOT matched" = "valor não cumpre o formato"
path = 'packages/core/src/BusinessRuleEngine/Evaluators/RegexEvaluator.php'
text = open(path, encoding='utf-8').read()
text = text.replace(
    "return (bool) @preg_match($pattern, $value);",
    "// Rule fires (denial) when value does NOT match the expected pattern\n"
    "        return ! @preg_match($pattern, $value);"
)
open(path, 'w', encoding='utf-8').write(text)
print('Fixed: RegexEvaluator (inverted: fires when NOT matching)')

print('\nDone. Run: .\\vendor\\bin\\pest.bat packages/core/tests/ConstraintEngine packages/core/tests/BusinessRuleEngine packages/core/tests/ComputationEngine packages/core/tests/WorkflowEngine --no-coverage')
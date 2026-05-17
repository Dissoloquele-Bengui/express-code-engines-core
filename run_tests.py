"""
run_tests.py — Executor de testes do monorepo laravel-engines
Executa Pest por engine, agrega resultados e gera um relatório visual no terminal.

Uso:
    python run_tests.py                  # todas as engines
    python run_tests.py --engine BRE     # só a BusinessRuleEngine
    python run_tests.py --fail-only      # só mostra engines com falhas
    python run_tests.py --no-color       # sem cores (para CI/logs)
    python run_tests.py --report         # gera engines_report.txt no final
    python run_tests.py --verbose        # output completo do Pest nas falhas
    python run_tests.py --debug          # mostra sempre o output bruto do Pest
"""

import os
import re
import sys
import time
import shutil
import argparse
import subprocess
from dataclasses import dataclass, field
from datetime import datetime
from typing import Optional

# ─── Configuração ──────────────────────────────────────────────

ROOT_DIR = os.path.dirname(os.path.abspath(__file__))
IS_WIN   = sys.platform == "win32"

def find_pest() -> Optional[str]:
    """Localiza o executável do Pest (pest.bat no Windows, pest no Unix)."""
    candidates = [
        os.path.join(ROOT_DIR, 'vendor', 'bin', 'pest.bat'),
        os.path.join(ROOT_DIR, 'vendor', 'bin', 'pest'),
    ]
    for p in candidates:
        if os.path.exists(p):
            return p
    return None

def find_composer() -> Optional[str]:
    """Localiza o composer no sistema."""
    for name in ('composer', 'composer.bat', 'composer.phar', 'composer.cmd'):
        p = shutil.which(name)
        if p:
            return p
    windows_paths = [
        r'C:\ProgramData\ComposerSetup\bin\composer.bat',
        r'C:\tools\composer\composer.bat',
        os.path.join(os.environ.get('APPDATA', ''), 'Composer', 'vendor', 'bin', 'composer.bat'),
        os.path.join(os.environ.get('APPDATA', ''), 'Composer', 'vendor', 'bin', 'composer'),
    ]
    for p in windows_paths:
        if os.path.exists(p):
            return p
    return None

# Mapeamento: nome legível → caminho relativo do directório de testes
ENGINES = [
    # ── Core ──────────────────────────────────────────────────
    ("ConstraintEngine",        "packages/core/tests/ConstraintEngine"),
    ("BusinessRuleEngine",      "packages/core/tests/BusinessRuleEngine"),
    ("ComputationEngine",       "packages/core/tests/ComputationEngine"),
    ("WorkflowEngine",          "packages/core/tests/WorkflowEngine"),
    ("Core Integration",        "packages/core/tests/Integration"),

    # ── Extended ──────────────────────────────────────────────
    ("ReactionEngine",          "packages/extended/tests/ReactionEngine"),
    ("NotificationEngine",      "packages/extended/tests/NotificationEngine"),
    ("DynamicPolicyEngine",     "packages/extended/tests/DynamicPolicyEngine"),
    ("SearchEngine",            "packages/extended/tests/SearchEngine"),
    ("IntegrationEngine",       "packages/extended/tests/IntegrationEngine"),
    ("OrchestrationEngine",     "packages/extended/tests/OrchestrationEngine"),
    ("Extended Integration",    "packages/extended/tests/Integration"),

    # ── AI ────────────────────────────────────────────────────
    ("AiEngine",                "packages/extended/tests/AiEngine"),
    ("DocumentEngine",          "packages/extended/tests/DocumentEngine"),
    ("ChatEngine",              "packages/extended/tests/ChatEngine"),

    # ── Cross-engine ──────────────────────────────────────────
    ("Cross / Roadmap",         "packages/extended/tests/Cross"),
]

# ─── Cores ANSI ────────────────────────────────────────────────

class C:
    RESET  = '\033[0m'
    BOLD   = '\033[1m'
    GREEN  = '\033[92m'
    RED    = '\033[91m'
    YELLOW = '\033[93m'
    BLUE   = '\033[94m'
    CYAN   = '\033[96m'
    GREY   = '\033[90m'
    WHITE  = '\033[97m'

USE_COLOR = True

def col(color: str, text: str) -> str:
    if not USE_COLOR:
        return text
    return f"{color}{text}{C.RESET}"

# ─── Estrutura de resultado ────────────────────────────────────

@dataclass
class EngineResult:
    name:        str
    path:        str
    status:      str          # "pass" | "fail" | "error" | "skip"
    passed:      int = 0
    failed:      int = 0
    skipped:     int = 0
    duration_s:  float = 0.0
    output:      str = ""
    error:       str = ""
    failures:    list = field(default_factory=list)

# ─── Parser de output do Pest ──────────────────────────────────

def parse_pest_output(output: str) -> dict:
    result = {"passed": 0, "failed": 0, "skipped": 0, "failures": []}

    # "Tests: 15 passed, 2 failed, 0 skipped"
    summary = re.search(
        r'Tests:\s*'
        r'(?:(\d+)\s+passed)?[,\s]*'
        r'(?:(\d+)\s+failed)?[,\s]*'
        r'(?:(\d+)\s+skipped)?',
        output
    )
    if summary:
        result["passed"]  = int(summary.group(1) or 0)
        result["failed"]  = int(summary.group(2) or 0)
        result["skipped"] = int(summary.group(3) or 0)

    # Testes que falharam
    for line in output.splitlines():
        stripped = line.strip()
        if stripped.startswith(('\xe2\x9c\x97', '\xc3\x97', 'x ')) or '  FAILED' in line or stripped.startswith('x '):
            name = re.sub(r'^[x\s✗×]+', '', stripped).strip()
            if name:
                result["failures"].append(name)

    return result

# ─── Detecção de bootstrap crash ──────────────────────────────

def is_bootstrap_crash(result: 'EngineResult') -> bool:
    """Detecta se o Pest falhou antes de correr qualquer teste."""
    return (
        result.status == "fail"
        and result.passed == 0
        and result.failed == 0
        and result.skipped == 0
    )

def extract_crash_hint(output: str) -> str:
    """Extrai a primeira linha de erro relevante do output do Pest."""
    if not output:
        return "(sem output)"
    for line in output.splitlines():
        line = line.strip()
        if not line:
            continue
        # Ignorar linhas de decoração do Pest
        if re.match(r'^[=\-_ ]*$', line):
            continue
        if line.startswith('PEST') or line.lower().startswith('pest'):
            continue
        # Primeira linha com conteúdo real
        return line[:120]
    return output.strip()[:120]

# ─── Execução de um engine ────────────────────────────────────

def run_engine(name: str, rel_path: str, pest_bin: str) -> EngineResult:
    abs_path = os.path.join(ROOT_DIR, rel_path)
    result   = EngineResult(name=name, path=rel_path, status="skip")

    if not os.path.exists(abs_path):
        result.error = f"Directorio nao encontrado: {rel_path}"
        return result

    # IMPORTANTE: usar caminho relativo a partir do ROOT_DIR
    # O Pest no Windows não lida bem com caminhos absolutos longos.
    # Passamos o caminho relativo (com / em vez de \) para evitar problemas.
    rel_posix = rel_path.replace("\\", "/")

    if IS_WIN:
        # No Windows: cmd /c pest.bat <path_relativo> [flags]
        cmd = ['cmd', '/c', pest_bin, rel_posix, '--no-coverage']
    else:
        cmd = [pest_bin, rel_posix, '--no-coverage']

    try:
        start = time.perf_counter()
        proc  = subprocess.run(
            cmd,
            cwd=ROOT_DIR,
            capture_output=True,
            text=True,
            timeout=120,
            encoding='utf-8',
            errors='replace',
        )
        elapsed = time.perf_counter() - start

        output = proc.stdout + proc.stderr
        parsed = parse_pest_output(output)

        result.passed     = parsed["passed"]
        result.failed     = parsed["failed"]
        result.skipped    = parsed["skipped"]
        result.failures   = parsed["failures"]
        result.output     = output
        result.duration_s = elapsed
        result.status     = "pass" if proc.returncode == 0 else "fail"

    except subprocess.TimeoutExpired:
        result.status = "error"
        result.error  = "Timeout (120s)"
    except Exception as e:
        result.status = "error"
        result.error  = str(e)

    return result

# ─── Formatacao ────────────────────────────────────────────────

STATUS_ICON  = {"pass": "OK", "fail": "FAIL", "error": "ERR", "skip": "--"}
STATUS_COLOR = {"pass": C.GREEN, "fail": C.RED, "error": C.YELLOW, "skip": C.GREY}

def fmt_status(status: str) -> str:
    icon  = STATUS_ICON.get(status, "?")
    color = STATUS_COLOR.get(status, C.WHITE)
    return col(color, f"[{icon:<4}]")

def fmt_duration(s: float) -> str:
    return f"{s*1000:.0f}ms" if s < 1 else f"{s:.2f}s"

def print_header():
    width = 72
    print()
    print(col(C.BOLD + C.BLUE, "=" * width))
    print(col(C.BOLD + C.BLUE, "  laravel-engines -- Test Runner".center(width)))
    print(col(C.GREY,          f"  {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}".center(width)))
    print(col(C.BOLD + C.BLUE, "=" * width))
    print()

def print_engine_row(r: EngineResult, debug: bool = False):
    status_str = fmt_status(r.status)
    name_str   = col(C.BOLD, f"{r.name:<26}")
    dur_str    = col(C.GREY, f"{fmt_duration(r.duration_s):>7}")

    if r.status == "skip":
        counts = col(C.GREY, "  (directorio nao existe)")
    elif r.status == "error":
        counts = col(C.YELLOW, f"  ! {r.error}")
    else:
        passed_s  = col(C.GREEN, f"{r.passed} passed")
        failed_s  = col(C.RED,   f"{r.failed} failed") if r.failed else col(C.GREY, "0 failed")
        skipped_s = col(C.GREY,  f"{r.skipped} skipped")
        counts    = f"  {passed_s}  {failed_s}  {skipped_s}"

    print(f"  {status_str}  {name_str}  {dur_str}{counts}")

    # Bootstrap crash: mostra hint de erro automaticamente
    if is_bootstrap_crash(r):
        hint = extract_crash_hint(r.output)
        print(col(C.YELLOW, f"             !! CRASH de bootstrap — {hint}"))

    if r.status == "fail" and r.failures:
        for name in r.failures[:10]:
            print(col(C.RED, f"             -> {name}"))
        if len(r.failures) > 10:
            print(col(C.GREY, f"             ... +{len(r.failures)-10} mais"))

    # Output bruto em modo debug ou verbose+crash
    if debug and r.output:
        print()
        print(col(C.CYAN, f"  ── Output bruto: {r.name} ──"))
        for line in r.output.splitlines()[:60]:
            print(col(C.GREY, f"    {line}"))
        if r.output.count('\n') > 60:
            print(col(C.GREY, f"    ... (truncado)"))
        print()

def print_summary(results: list):
    total_pass   = sum(1 for r in results if r.status == "pass")
    total_fail   = sum(1 for r in results if r.status == "fail")
    total_error  = sum(1 for r in results if r.status == "error")
    total_skip   = sum(1 for r in results if r.status == "skip")
    total_tests  = sum(r.passed + r.failed for r in results)
    failed_tests = sum(r.failed for r in results)
    total_time   = sum(r.duration_s for r in results)
    crashes      = sum(1 for r in results if is_bootstrap_crash(r))

    print()
    print(col(C.BOLD + C.BLUE, "-" * 72))
    print(col(C.BOLD, "  RESUMO"))
    print()

    parts = [col(C.GREEN, f"{total_pass} engines OK")]
    parts.append(col(C.RED, f"{total_fail} com falhas") if total_fail else col(C.GREY, "0 com falhas"))
    if total_error: parts.append(col(C.YELLOW, f"{total_error} com erro"))
    if total_skip:  parts.append(col(C.GREY,   f"{total_skip} skipped"))
    print(f"  Engines:  {'  '.join(parts)}")

    t_ok = col(C.GREEN, f"{total_tests - failed_tests} passed")
    t_fl = col(C.RED,   f"{failed_tests} failed") if failed_tests else col(C.GREY, "0 failed")
    print(f"  Testes:   {t_ok}  {t_fl}")
    print(f"  Tempo:    {col(C.GREY, fmt_duration(total_time))}")
    print()

    if crashes:
        print(col(C.BOLD + C.YELLOW,
            f"  ATENÇÃO: {crashes} engine(s) com crash de bootstrap (0 testes executados)."))
        print(col(C.YELLOW,
            "  Corre com --debug para ver o output bruto, ou corre directamente:"))
        print(col(C.GREY,
            "      .\\vendor\\bin\\pest.bat packages/core/tests/ConstraintEngine --no-coverage"))
        print()

    if total_fail == 0 and total_error == 0:
        print(col(C.BOLD + C.GREEN, "  OK - Todas as engines passaram!"))
    else:
        if total_fail:
            print(col(C.BOLD + C.RED, f"  FAIL - {total_fail} engine(s) com testes a falhar."))
        if total_error:
            print(col(C.BOLD + C.YELLOW, f"  ERR  - {total_error} engine(s) com erro de execucao."))

    print(col(C.BOLD + C.BLUE, "-" * 72))
    print()

def write_report(results: list, path: str):
    lines = [
        "laravel-engines -- Test Report",
        f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        "=" * 72, "",
    ]
    for r in results:
        crash_note = " [BOOTSTRAP CRASH]" if is_bootstrap_crash(r) else ""
        lines.append(
            f"[{r.status.upper():<4}]  {r.name:<26}  {fmt_duration(r.duration_s):>7}  "
            f"{r.passed}p / {r.failed}f / {r.skipped}s{crash_note}"
        )
        if is_bootstrap_crash(r):
            lines.append(f"         -> ERROR: {extract_crash_hint(r.output)}")
        elif r.status == "fail":
            for n in r.failures:
                lines.append(f"         -> FAIL: {n}")
        if r.status in ("error", "skip"):
            lines.append(f"         -> {r.error or r.path}")

    total_pass  = sum(1 for r in results if r.status == "pass")
    total_fail  = sum(1 for r in results if r.status == "fail")
    total_tests = sum(r.passed + r.failed for r in results)
    failed_t    = sum(r.failed for r in results)
    lines += ["", "=" * 72,
              f"Engines: {total_pass} OK / {total_fail} FAIL",
              f"Tests:   {total_tests - failed_t} passed / {failed_t} failed"]

    with open(path, "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    print(col(C.GREY, f"  Relatorio guardado em: {path}"))

# ─── Main ──────────────────────────────────────────────────────

def main():
    global USE_COLOR

    parser = argparse.ArgumentParser(description="Executor de testes laravel-engines")
    parser.add_argument("--engine",    help="Filtrar por nome parcial, abreviatura ou iniciais (ex: BRE, Notif, AI)")
    parser.add_argument("--fail-only", action="store_true", help="Mostrar so engines com falhas")
    parser.add_argument("--no-color",  action="store_true", help="Sem cores ANSI")
    parser.add_argument("--report",    action="store_true", help="Gerar engines_report.txt")
    parser.add_argument("--verbose",   action="store_true", help="Output completo do Pest em falhas")
    parser.add_argument("--debug",     action="store_true", help="Output bruto do Pest para cada engine (diagnóstico)")
    args = parser.parse_args()

    if args.no_color:
        USE_COLOR = False

    # ── Filtro de engine ───────────────────────────────────────
    engines_to_run = ENGINES
    if args.engine:
        needle = args.engine.lower().replace(" ", "")

        def matches(name: str) -> bool:
            words    = re.findall(r'[A-Z][a-z]*|\d+', name)
            initials = "".join(w[0] for w in words).lower()
            if needle in name.lower().replace(" ", ""):
                return True
            if needle == initials:
                return True
            if initials.startswith(needle):
                return True
            if any(w.lower().startswith(needle) for w in words):
                return True
            return False

        engines_to_run = [(n, p) for n, p in ENGINES if matches(n)]
        if not engines_to_run:
            print(f"Nenhuma engine encontrada com '{args.engine}'.")
            print("Engines disponiveis (aceita nome parcial, abreviatura ou iniciais):")
            for name, _ in ENGINES:
                words    = re.findall(r'[A-Z][a-z]*|\d+', name)
                initials = "".join(w[0] for w in words).upper()
                print(f"  {name:<26}  [{initials}]")
            sys.exit(1)

    print_header()

    # ── Verificar/instalar Pest ────────────────────────────────
    pest_bin = find_pest()
    if not pest_bin:
        print(col(C.YELLOW, "  vendor/ nao encontrado. A tentar 'composer install'...\n"))
        composer = find_composer()
        if not composer:
            print(col(C.RED,
                "  Composer nao encontrado no sistema.\n"
                "  Instala o Composer em https://getcomposer.org e corre:\n"
                "      composer install\n"
                "  na pasta do projecto, depois volta a executar este script."
            ))
            sys.exit(1)

        ret = subprocess.run(
            [composer, 'install', '--no-interaction'],
            cwd=ROOT_DIR
        )
        if ret.returncode != 0:
            print(col(C.RED, "\n  'composer install' falhou. Verifica o output acima."))
            sys.exit(1)

        pest_bin = find_pest()
        if not pest_bin:
            print(col(C.RED, "\n  Pest nao encontrado apos 'composer install'. Verifica o composer.json."))
            sys.exit(1)
        print()

    # ── Executar testes ────────────────────────────────────────
    results = []
    print(col(C.GREY, f"  {'ENGINE':<26}  {'TEMPO':>7}  RESULTADO"))
    print(col(C.GREY, "  " + "-" * 68))

    for name, path in engines_to_run:
        print(f"  [...] {col(C.GREY, name):<26}", end="", flush=True)
        result = run_engine(name, path, pest_bin)
        results.append(result)
        print("\r", end="")

        if args.fail_only and result.status in ("pass", "skip"):
            continue

        print_engine_row(result, debug=args.debug)

        if args.verbose and result.status == "fail" and result.output and not is_bootstrap_crash(result):
            print()
            for line in result.output.splitlines():
                print(col(C.GREY, f"    {line}"))
            print()

    print_summary(results)

    if args.report:
        write_report(results, os.path.join(ROOT_DIR, "engines_report.txt"))

    sys.exit(1 if any(r.status in ("fail", "error") for r in results) else 0)


if __name__ == "__main__":
    main()
"""
split_classes_v2.py — Parte ficheiros PHP multi-classe em ficheiros PSR-4 individuais.
Usa um parser de chaves robusto que não se confunde com docblocks nos construtores.
"""
import re, os, sys

ROOT = os.path.dirname(os.path.abspath(__file__))

# Ficheiros originais multi-classe e as suas pastas
SOURCES = {
    "packages/shared/src/DTOs": [
        "ConstraintDTO.php", "ComputationWorkflowDTO.php", "BusinessRuleDTO.php",
        "AiChatDocumentDTO.php", "AiPhaseDTO.php", "IntegrationDTO.php",
        "OrchestrationDTO.php", "PolicyDTO.php", "ReactionNotificationDTO.php",
        "SearchDTO.php", "WorkflowDTO.php",
    ],
    "packages/shared/src/Contracts": [
        "AiContracts.php", "AiPhaseContracts.php", "ReactionNotificationContracts.php",
        "IntegrationContracts.php", "OrchestrationContracts.php",
        "SearchContracts.php", "WebhookContracts.php",
    ],
    "packages/shared/src/ValueObjects": [
        "Responses.php", "AiResponses.php", "ReactionNotificationResponses.php",
    ],
}


def find_class_blocks(content: str):
    """
    Encontra todos os blocos class/interface/enum/trait no ficheiro.
    Retorna [(class_name, full_block_text), ...]
    """
    # Procurar declarações de classe/interface de top-level
    # Padrão: opcional docblock + modificadores + keyword + nome
    pattern = re.compile(
        r'((?:/\*\*(?:(?!\*/).)*\*/\s*)?'  # docblock opcional (non-greedy)
        r'(?:(?:final|abstract|readonly)\s+)*'  # modificadores
        r'(?:class|interface|enum|trait)\s+'  # keyword
        r'(\w+))',  # nome da classe
        re.DOTALL
    )

    results = []
    for m in pattern.finditer(content):
        class_name = m.group(2)
        start = m.start()

        # Encontrar a primeira { após o nome da classe
        brace_pos = content.find("{", m.end())
        if brace_pos == -1:
            continue

        # Contar chaves para encontrar o } de fecho
        depth = 1
        pos = brace_pos + 1
        while pos < len(content) and depth > 0:
            ch = content[pos]
            if ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
            elif ch == '/' and pos + 1 < len(content) and content[pos + 1] == '/':
                # Skip single-line comment
                pos = content.find('\n', pos)
                if pos == -1:
                    break
            elif ch == "'" or ch == '"':
                # Skip string literal
                quote = ch
                pos += 1
                while pos < len(content) and content[pos] != quote:
                    if content[pos] == '\\':
                        pos += 1  # skip escaped char
                    pos += 1
            pos += 1

        if depth == 0:
            block = content[start:pos]
            results.append((class_name, block))

    return results


def extract_namespace(content: str) -> str:
    m = re.search(r'^namespace\s+([\w\\]+)\s*;', content, re.MULTILINE)
    return m.group(1) if m else ''


def extract_uses(content: str) -> str:
    """Extrai use statements antes da primeira declaração de classe."""
    # Encontrar posição da primeira classe
    class_pos = re.search(
        r'(?:final|abstract|readonly|class|interface|enum|trait)\s',
        content
    )
    if class_pos:
        header = content[:class_pos.start()]
    else:
        header = content

    uses = re.findall(r'^use\s+[^;]+;', header, re.MULTILINE)
    return '\n'.join(uses)


def process_file(src_dir: str, filename: str, dry_run: bool):
    src_path = os.path.join(ROOT, src_dir, filename)
    if not os.path.exists(src_path):
        print(f"  SKIP (not found): {src_dir}/{filename}")
        return

    content = open(src_path, encoding='utf-8').read()
    namespace = extract_namespace(content)
    uses = extract_uses(content)

    blocks = find_class_blocks(content)
    if len(blocks) <= 1:
        print(f"  SKIP (single class): {filename}")
        return

    created = []
    skipped = []
    for class_name, block in blocks:
        dest_path = os.path.join(ROOT, src_dir, f"{class_name}.php")

        php = "<?php\n\ndeclare(strict_types=1);\n\n"
        php += f"namespace {namespace};\n\n"
        if uses:
            php += uses + "\n\n"
        php += block.strip() + "\n"

        if dry_run:
            created.append(class_name)
            continue

        # Sempre sobrescrever — os ficheiros antigos estão corrompidos
        with open(dest_path, 'w', encoding='utf-8', newline='\n') as f:
            f.write(php)
        created.append(class_name)

    action = "WOULD CREATE" if dry_run else "CREATED"
    print(f"  {filename}: {action} {created}")


def main():
    dry_run = "--dry-run" in sys.argv
    if dry_run:
        print("=== DRY RUN ===\n")

    for src_dir, files in SOURCES.items():
        print(f"\n--- {src_dir} ---")
        for f in files:
            process_file(src_dir, f, dry_run)

    if not dry_run:
        print("\nFicheiros criados. Corre agora:")
        print("  composer dump-autoload")
        print("  python run_tests.py")


if __name__ == "__main__":
    main()
import argparse
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]

# PHP files added specifically for this project (not part of a fresh Laravel skeleton).
CUSTOM_PHP_FILES = [
    "app/Events/ProjectFilesystemEvent.php",
    "app/Events/ProjectRealtimeEvent.php",
    "app/Http/Middleware/EnsureAdmin.php",
    "app/Http/Controllers/AdminController.php",
    "app/Http/Controllers/AdminProjectController.php",
    "app/Http/Controllers/AdminProjectInvitationController.php",
    "app/Http/Controllers/AdminProjectParticipantController.php",
    "app/Http/Controllers/AdminProjectSnapshotController.php",
    "app/Http/Controllers/AdminProjectTerminalSessionController.php",
    "app/Http/Controllers/AuthController.php",
    "app/Http/Controllers/ForgejoAuthController.php",
    "app/Http/Controllers/ProfileController.php",
    "app/Http/Controllers/ProjectCodeCommentController.php",
    "app/Http/Controllers/ProjectController.php",
    "app/Http/Controllers/ProjectFilesystemController.php",
    "app/Http/Controllers/ProjectForgejoController.php",
    "app/Http/Controllers/ProjectInfoController.php",
    "app/Http/Controllers/ProjectInvitationController.php",
    "app/Http/Controllers/ProjectParticipantController.php",
    "app/Http/Controllers/ProjectRealtimeController.php",
    "app/Http/Controllers/ProjectSnapshotController.php",
    "app/Http/Controllers/ProjectTerminalController.php",
    "app/Http/Controllers/UserController.php",
    "app/Models/Admin.php",
    "app/Models/Project.php",
    "app/Models/ProjectCodeComment.php",
    "app/Models/ProjectInvitation.php",
    "app/Models/ProjectParticipant.php",
    "app/Models/ProjectSnapshot.php",
    "app/Models/ProjectTerminalSession.php",
    "app/Services/ForgejoService.php",
    "app/Services/ProjectAccessService.php",
    "app/Services/ProjectFilesystemService.php",
    "app/Services/ProjectGitService.php",
    "app/Services/ProjectTerminalService.php",
    "config/reverb.php",
    "config/terminal.php",
    "routes/api.php",
    "routes/channels.php",
    "database/migrations/2026_02_08_095758_projects_table.php",
    "database/migrations/2026_02_08_095854_project_participants_table.php",
    "database/migrations/2026_02_08_095952_invitations_table.php",
    "database/migrations/2026_02_08_100039_project_snapshots_table.php",
    "database/migrations/2026_02_08_101136_updated_at_triggers.php",
    "database/migrations/2026_02_08_115529_create_personal_access_tokens_table.php",
    "database/migrations/2026_02_08_180000_admins_table.php",
    "database/migrations/2026_02_12_120000_add_forgejo_fields_to_users_table.php",
    "database/migrations/2026_02_12_120010_add_forgejo_fields_to_projects_table.php",
    "database/migrations/2026_02_12_170000_create_sessions_table.php",
    "database/migrations/2026_02_12_224500_add_profile_avatar_fields_to_users_table.php",
    "database/migrations/2026_02_12_231000_add_theme_to_users_table.php",
    "database/migrations/2026_02_16_200000_create_project_terminal_sessions_table.php",
    "database/migrations/2026_02_19_120000_add_role_to_project_participants_table.php",
    "database/migrations/2026_02_21_123229_create_project_code_comments_table.php",
]

# JS/TS files added for project logic.
CUSTOM_JS_TS_FILES = [
    "terminal-gateway/src/backendReporter.js",
    "terminal-gateway/src/index.js",
    "terminal-gateway/src/ptyManager.js",
    "terminal-gateway/src/ticketVerifier.js",
    "frontend/src/main.js",
    "frontend/src/router/index.js",
    "frontend/src/config/avatarPresets.js",
    "frontend/src/i18n/index.js",
    "frontend/src/i18n/messages.js",
    "frontend/src/services/api.js",
    "frontend/src/services/auth.js",
    "frontend/src/services/bigOtest.js",
    "frontend/src/services/collabOt.js",
    "frontend/src/services/device.js",
    "frontend/src/services/preferences.js",
    "frontend/src/services/realtime.js",
    "frontend/src/services/terminal.js",
    "frontend/src/composables/useAceEditor.ts",
    "frontend/src/composables/useEditorLayout.ts",
    "frontend/src/composables/useForgejoIntegration.ts",
    "frontend/src/composables/useProjectFilesystem.ts",
    "frontend/src/composables/useProjectSettings.ts",
]


EMPTY_ASSIGNMENT_PATTERN = re.compile(
    r"""
    ^
    (?:\s*(?:const|let|var)\s+)?               # Optional JS declaration
    (?:\$?[A-Za-z_][\w$]*                       # Variable name (Python/JS/PHP)
       (?:\.[A-Za-z_][\w$]*)*                   # Optional dotted access
    )
    (?:\s*:\s*[^=]+)?                           # Optional type annotation
    \s*=\s*
    (?:''|""|None|null|undefined|\[\]|\{\}|\(\))
    \s*;?\s*
    $
    """,
    re.VERBOSE,
)

PHP_DIRECTIVE_PATTERN = re.compile(
    r"""
    ^
    (?:
        <\?(?:php)?|
        \?>|
        declare\s*\([^)]*\)\s*;|
        namespace\s+[\w\\]+\s*;|
        use\s+.+;
    )
    \s*$
    """,
    re.IGNORECASE | re.VERBOSE,
)

JS_TS_DIRECTIVE_PATTERN = re.compile(
    r"""
    ^
    (?:
        import\b.+;|
        export\s+\{.+\}\s*;|
        export\s+\{.+\}\s+from\s+['"].+['"]\s*;|
        ['"]use strict['"]\s*;|
        (?:const|let|var)\s+[\w${},\s]+\s*=\s*require\s*\([^)]*\)\s*;
    )
    \s*$
    """,
    re.IGNORECASE | re.VERBOSE,
)

STRUCTURE_ONLY_PATTERN = re.compile(r"^[\[\]\(\)\{\};,]+$")
ATTRIBUTE_PATTERN = re.compile(r"^#\[[^\]]+\]$")

TYPE_HEADER_START_PATTERN = re.compile(
    r"^(?:(?:final|abstract)\s+)?(?:class|interface|trait|enum)\b",
    re.IGNORECASE,
)
FUNCTION_HEADER_START_PATTERN = re.compile(
    r"^(?:(?:public|protected|private)\s+)?(?:(?:static|final|abstract|async)\s+)*(?:function|fn)\b",
    re.IGNORECASE,
)
JS_FUNCTION_HEADER_START_PATTERN = re.compile(
    r"^(?:export\s+)?(?:default\s+)?(?:async\s+)?function\b",
    re.IGNORECASE,
)
JS_ARROW_FUNCTION_DECL_PATTERN = re.compile(
    r"^(?:export\s+)?(?:const|let|var)\s+[A-Za-z_$][\w$]*\s*=\s*(?:async\s*)?(?:\([^=]*\)|[A-Za-z_$][\w$]*)\s*=>",
    re.IGNORECASE,
)
JS_ARROW_FUNCTION_MULTILINE_START_PATTERN = re.compile(
    r"^(?:export\s+)?(?:const|let|var)\s+[A-Za-z_$][\w$]*\s*=\s*(?:async\s*)?\(\s*$",
    re.IGNORECASE,
)


def strip_comments(line: str, in_block_comment: bool) -> tuple[str, bool]:
    result: list[str] = []
    i = 0
    in_single_quote = False
    in_double_quote = False
    escaped = False

    while i < len(line):
        ch = line[i]
        nxt = line[i + 1] if i + 1 < len(line) else ""

        if in_block_comment:
            if ch == "*" and nxt == "/":
                in_block_comment = False
                i += 2
                continue
            i += 1
            continue

        if in_single_quote or in_double_quote:
            result.append(ch)
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif in_single_quote and ch == "'":
                in_single_quote = False
            elif in_double_quote and ch == '"':
                in_double_quote = False
            i += 1
            continue

        if ch == "/" and nxt == "*":
            in_block_comment = True
            i += 2
            continue

        if (ch == "/" and nxt == "/") or ch == "#":
            break

        if ch == "'":
            in_single_quote = True
            result.append(ch)
            i += 1
            continue

        if ch == '"':
            in_double_quote = True
            result.append(ch)
            i += 1
            continue

        result.append(ch)
        i += 1

    return "".join(result), in_block_comment


def is_empty_assignment(code_line: str) -> bool:
    return bool(EMPTY_ASSIGNMENT_PATTERN.match(code_line))


def is_directive_line(code_line: str, file_suffix: str) -> bool:
    if file_suffix == ".php":
        return bool(PHP_DIRECTIVE_PATTERN.match(code_line))
    if file_suffix in {".js", ".ts"}:
        return bool(JS_TS_DIRECTIVE_PATTERN.match(code_line))
    return False


def starts_directive_block(code_line: str, file_suffix: str) -> bool:
    if file_suffix in {".js", ".ts"}:
        if code_line.startswith("import ") and ";" not in code_line:
            return True
        if code_line.startswith("export {") and ";" not in code_line:
            return True
    if file_suffix == ".php":
        if code_line.startswith("use ") and ";" not in code_line:
            return True
    return False


def header_start_kind(code_line: str, file_suffix: str) -> str | None:
    if TYPE_HEADER_START_PATTERN.match(code_line):
        return "generic"
    if FUNCTION_HEADER_START_PATTERN.match(code_line):
        return "generic"
    if file_suffix in {".js", ".ts"}:
        if JS_FUNCTION_HEADER_START_PATTERN.match(code_line):
            return "generic"
        if JS_ARROW_FUNCTION_DECL_PATTERN.match(code_line):
            return "arrow"
        if JS_ARROW_FUNCTION_MULTILINE_START_PATTERN.match(code_line):
            return "arrow"
    return None


def header_is_complete(code_line: str, header_kind: str) -> bool:
    if header_kind == "arrow":
        return "=>" in code_line or code_line.endswith(";")
    return "{" in code_line or code_line.endswith(";")


def is_structure_only_line(code_line: str) -> bool:
    return bool(STRUCTURE_ONLY_PATTERN.match(code_line))


def is_header_attribute(code_line: str) -> bool:
    return bool(ATTRIBUTE_PATTERN.match(code_line))


def count_logic_lines(file_path: Path) -> int:
    count = 0
    in_block_comment = False
    in_directive_block = False
    in_header = False
    header_kind = "generic"
    file_suffix = file_path.suffix.lower()

    with file_path.open("r", encoding="utf-8", errors="ignore") as source:
        for raw_line in source:
            code_line, in_block_comment = strip_comments(raw_line, in_block_comment)
            stripped = code_line.strip()

            if not stripped:
                continue

            if in_directive_block:
                if ";" in stripped:
                    in_directive_block = False
                continue

            if starts_directive_block(stripped, file_suffix):
                in_directive_block = True
                continue

            if is_directive_line(stripped, file_suffix):
                continue

            if in_header:
                if header_is_complete(stripped, header_kind):
                    in_header = False
                continue

            if is_header_attribute(stripped):
                continue

            start_kind = header_start_kind(stripped, file_suffix)
            if start_kind is not None:
                if not header_is_complete(stripped, start_kind):
                    in_header = True
                    header_kind = start_kind
                continue

            if is_structure_only_line(stripped):
                continue

            if is_empty_assignment(stripped):
                continue

            count += 1

    return count


def count_logic_lines_for_paths(paths: list[str]) -> tuple[int, list[str]]:
    total = 0
    missing: list[str] = []

    for relative_path in paths:
        file_path = ROOT / Path(relative_path)
        if not file_path.exists() or not file_path.is_file():
            missing.append(relative_path)
            continue
        total += count_logic_lines(file_path)

    return total, missing


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Count logic lines excluding blank lines, comments, directives, headers and empty assignments. "
            "By default counts predefined custom PHP and JS/TS file lists."
        )
    )
    parser.add_argument(
        "--path",
        type=Path,
        help="Optional single file path. If provided, counts only this file.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.path:
        file_path = args.path
        if not file_path.exists():
            raise FileNotFoundError(f"File not found: {file_path}")
        if not file_path.is_file():
            raise IsADirectoryError(f"Path is not a file: {file_path}")
        print(count_logic_lines(file_path))
        return

    php_total, php_missing = count_logic_lines_for_paths(CUSTOM_PHP_FILES)
    js_ts_total, js_ts_missing = count_logic_lines_for_paths(CUSTOM_JS_TS_FILES)

    print(f"PHP total: {php_total}")
    print(f"JS/TS total: {js_ts_total}")

    if php_missing:
        print("Missing PHP files:", file=sys.stderr)
        for missing in php_missing:
            print(f"  - {missing}", file=sys.stderr)

    if js_ts_missing:
        print("Missing JS/TS files:", file=sys.stderr)
        for missing in js_ts_missing:
            print(f"  - {missing}", file=sys.stderr)


if __name__ == "__main__":
    main()

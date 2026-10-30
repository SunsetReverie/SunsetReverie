#!/usr/bin/env bash

#
# 本地 CI：把 GitHub Actions 要跑的检查在本地先跑一遍。
#
# 用法：
#   ./scripts/ci.sh                    本地跑（缺 phpstan.phar 时跳过并提示）
#   CI=true ./scripts/ci.sh            CI 模式（缺 phar 视为失败）
#   SKIP_STATIC=true ./scripts/ci.sh   跳过静态分析（矩阵 job 用，避免每版本重复下载 30MB）
#
# 原则：不依赖 composer；每步独立报告，失败不中断后续步骤。
#

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ -t 1 ]; then
    C_OK=$'\033[32m'; C_FAIL=$'\033[31m'; C_WARN=$'\033[33m'; C_DIM=$'\033[2m'; C_END=$'\033[0m'
else
    C_OK=''; C_FAIL=''; C_WARN=''; C_DIM=''; C_END=''
fi

PASS=0
FAIL=0
SKIP=0

step() { printf '\n%s▶ %s%s\n' "$C_DIM" "$1" "$C_END"; }
ok()   { printf '  %s✓%s %s\n' "$C_OK" "$C_END" "$1"; PASS=$((PASS + 1)); }
bad()  { printf '  %s✗%s %s\n' "$C_FAIL" "$C_END" "$1"; FAIL=$((FAIL + 1)); }
skip() { printf '  %s-%s %s\n' "$C_WARN" "$C_END" "$1"; SKIP=$((SKIP + 1)); }

# ── 1. 环境 ──────────────────────────────────────────────────────
step "环境"

printf '  PHP %s\n' "$(php -r 'echo PHP_VERSION;')"

MISSING=()

for ext in json mbstring fileinfo pdo openssl; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" || MISSING+=("$ext")
done

if [ ${#MISSING[@]} -eq 0 ]; then
    ok "必需扩展齐全"
else
    bad "缺少扩展：${MISSING[*]}"
fi

# ── 2. 语法检查 ──────────────────────────────────────────────────
step "语法检查"

BAD=0
TOTAL=0

while IFS= read -r file; do
    TOTAL=$((TOTAL + 1))
    php -l "$file" > /dev/null 2>&1 || { printf '    %s\n' "$file"; BAD=$((BAD + 1)); }
done < <(find core components tests skeleton -name '*.php' -type f 2>/dev/null)

if [ "$BAD" -eq 0 ]; then ok "$TOTAL 个文件语法正确"; else bad "$BAD / $TOTAL 语法错误"; fi

# ── 3. 单元测试 ──────────────────────────────────────────────────
step "单元测试（SQLite 内存库）"

if php tests/run.php > /tmp/ci-tests.log 2>&1; then
    ok "$(grep -E '通过 [0-9]+，失败' /tmp/ci-tests.log | tail -1 | sed 's/\x1b\[[0-9;]*m//g')"
else
    bad "测试失败"
    grep -E '✗|失败详情' /tmp/ci-tests.log | head -6 | sed 's/^/    /'
fi

# ── 4. 架构检查 ──────────────────────────────────────────────────
step "架构检查（ARCH-1 ～ ARCH-8）"

if php bin/sr lint:architecture > /tmp/ci-arch.log 2>&1; then
    ok "8 项全过"
else
    bad "架构检查未通过"
    grep -E 'FAIL' /tmp/ci-arch.log | head -6 | sed 's/^/    /'
fi

# ── 5. 静态分析 ──────────────────────────────────────────────────
step "静态分析（PHPStan）"

if [ "${SKIP_STATIC:-false}" = "true" ]; then
    skip "SKIP_STATIC=true，跳过（由独立的 static job 承担）"
elif [ -f phpstan.phar ]; then
    if php phpstan.phar analyse --memory-limit=1G --no-progress > /tmp/ci-phpstan.log 2>&1; then
        ok "level $(grep -E '^\s+level:' phpstan.neon | tr -d ' ' | cut -d: -f2) 零错误"
    else
        bad "静态分析有错误"
        grep -oE 'Found [0-9]+ errors?' /tmp/ci-phpstan.log | tail -1 | sed 's/^/    /'
    fi
elif [ "${CI:-false}" = "true" ]; then
    bad "CI 模式必须有 phpstan.phar"
else
    skip "phpstan.phar 不存在（下载见 docs/v1.0-checklist.md 的 P0-1）"
fi

# ── 6. 数据库方言（可选）─────────────────────────────────────────
step "数据库方言"

if [ -z "${SR_TEST_DB:-}" ]; then
    skip "未设置 SR_TEST_DB，跳过方言契约测试（sqlite / mysql / pgsql）"
else
    if php tests/run.php --filter=DialectContract > /tmp/ci-db.log 2>&1; then
        ok "SR_TEST_DB=${SR_TEST_DB} 通过"
    else
        bad "SR_TEST_DB=${SR_TEST_DB} 失败"
    fi
fi

# ── 汇总 ─────────────────────────────────────────────────────────
printf '\n─────────────────────────────\n'

if [ "$FAIL" -eq 0 ]; then
    printf '%s全部通过%s（%d 通过 / %d 跳过）\n' "$C_OK" "$C_END" "$PASS" "$SKIP"
    exit 0
fi

printf '%s失败 %d 项%s（%d 通过 / %d 跳过）\n' "$C_FAIL" "$FAIL" "$C_END" "$PASS" "$SKIP"
exit 1

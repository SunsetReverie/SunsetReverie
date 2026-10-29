#!/usr/bin/env node
/**
 * 夕梦框架文档构建脚本：将产品规划 Markdown 转换为单文件 HTML 文档站。
 *
 * 只实现本项目文档所需的 Markdown 子集（标题/段落/列表/表格/代码块/引用/分隔线/行内格式），
 * 避免引入任何第三方依赖。
 *
 * 用法：node docs/tools/md2html.mjs <input.md> <output.html>
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { basename } from 'node:path';

const [, , inputPath, outputPath] = process.argv;
if (!inputPath || !outputPath) {
  console.error('用法: node docs/tools/md2html.mjs <input.md> <output.html>');
  process.exit(1);
}

const raw = readFileSync(inputPath, 'utf8');

/* ------------------------------------------------------------------ *
 * 工具函数
 * ------------------------------------------------------------------ */

/**
 * HTML 实体转义。
 *
 * @param {string} s 原始文本
 * @returns {string} 转义后的文本
 */
function esc(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * 生成与 GitHub 风格一致的锚点 ID（保留中文，去除标点，空格转连字符）。
 *
 * @param {string} text 标题文本
 * @returns {string} 锚点
 */
function slug(text) {
  return text
    .toLowerCase()
    .replace(/`|\*|_|~/g, '')
    .replace(/[^\p{L}\p{N}\s.-]/gu, '')
    .replace(/\./g, '')
    .trim()
    .replace(/\s+/g, '-');
}

const KEYWORDS =
  'declare|strict_types|namespace|use|final|abstract|class|interface|trait|enum|extends|implements|' +
  'public|private|protected|readonly|static|const|function|fn|return|new|throw|try|catch|finally|' +
  'if|else|elseif|foreach|for|while|switch|case|default|break|continue|match|yield|echo|print|' +
  'instanceof|insteadof|global|isset|unset|empty|list|as|and|or|xor|true|false|null|self|parent|';

/**
 * 为代码片段生成基础语法着色 HTML（PHP / Bash / JSON / 通用）。
 *
 * @param {string} code 代码原文
 * @param {string} lang 语言标识
 * @returns {string} 着色后的 HTML
 */
function highlight(code, lang) {
  const isPhp = ['php'].includes(lang);
  const isShell = ['bash', 'sh', 'shell', 'zsh', 'dotenv'].includes(lang);
  const isJson = ['json'].includes(lang);
  const isSql = ['sql'].includes(lang);

  const pattern = new RegExp(
    [
      '(\\/\\*[\\s\\S]*?\\*\\/)', // 块注释
      '(\\/\\/[^\\n]*)', // 行注释
      '((?<!#[^\\[])#[^\\n]*)', // 井号注释
      '(\'(?:\\\\.|[^\'\\\\])*\')', // 单引号字符串
      '("(?:\\\\.|[^"\\\\])*")', // 双引号字符串
      '(\\$[A-Za-z_][A-Za-z0-9_]*)', // PHP 变量
      '(\\b\\d+(?:\\.\\d+)?\\b)', // 数字
      isJson ? '(\\b(?:true|false|null)\\b)' : '((?!))',
      `(\\b(?:${KEYWORDS.slice(0, -1)})\\b)`, // 关键字
    ].join('|'),
    'g',
  );

  let out = '';
  let last = 0;
  let m;
  while ((m = pattern.exec(code)) !== null) {
    out += esc(code.slice(last, m.index));
    last = m.index + m[0].length;
    const t = m[0];
    if (m[1] || m[2] || m[3]) {
      out += `<span class="c-com">${esc(t)}</span>`;
    } else if (m[4] || m[5]) {
      out += `<span class="c-str">${esc(t)}</span>`;
    } else if (m[6]) {
      out += `<span class="c-var">${esc(t)}</span>`;
    } else if (m[7]) {
      out += `<span class="c-num">${esc(t)}</span>`;
    } else if (m[8]) {
      out += `<span class="c-lit">${esc(t)}</span>`;
    } else {
      out += `<span class="c-kw">${esc(t)}</span>`;
    }
    if (m[0].length === 0) pattern.lastIndex++;
  }
  out += esc(code.slice(last));

  if (isShell && !/<\/span>/.test(out)) {
    // Bash 命令首词着色兜底
    out = out.replace(/^(\s*)([a-z-]+)/gm, '$1<span class="c-kw">$2</span>');
  }
  if (isSql) {
    out = out.replace(
      /\b(SELECT|FROM|WHERE|INSERT|UPDATE|DELETE|CREATE|TABLE|INDEX|JOIN|ON|GROUP|ORDER|LIMIT)\b/g,
      '<span class="c-kw">$1</span>',
    );
  }
  return out;
}

/**
 * 行内格式处理：行内代码、加粗、斜体、链接、删除线。
 *
 * @param {string} text 原始行内文本
 * @returns {string} 处理后的 HTML
 */
function inline(text) {
  const codes = [];
  let s = text.replace(/`([^`]+)`/g, (_, c) => {
    codes.push(c);
    return `\u0000${codes.length - 1}\u0000`;
  });

  s = esc(s);
  s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>');
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  s = s.replace(/~~([^~]+)~~/g, '<del>$1</del>');
  s = s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code class="inline">${esc(codes[Number(i)])}</code>`);
  return s;
}

/* ------------------------------------------------------------------ *
 * 块级解析
 * ------------------------------------------------------------------ */

const lines = raw.split(/\r?\n/);
const toc = [];
const html = [];
let i = 0;

while (i < lines.length) {
  const line = lines[i];

  // 代码块
  const fence = line.match(/^```(\w*)\s*$/);
  if (fence) {
    const lang = fence[1] || 'text';
    const buf = [];
    i++;
    while (i < lines.length && !/^```\s*$/.test(lines[i])) {
      buf.push(lines[i]);
      i++;
    }
    i++; // 跳过结束围栏
    const code = buf.join('\n');
    const langLabel = lang === 'text' ? 'TEXT' : lang.toUpperCase();
    html.push(
      `<div class="code" data-lang="${lang}">` +
        `<div class="code-bar"><span class="code-lang">${langLabel}</span>` +
        `<button class="copy" type="button" aria-label="复制代码">复制</button></div>` +
        `<pre><code>${highlight(code, lang)}</code></pre></div>`,
    );
    continue;
  }

  // 空行
  if (/^\s*$/.test(line)) {
    i++;
    continue;
  }

  // 分隔线
  if (/^---+\s*$/.test(line)) {
    html.push('<hr>');
    i++;
    continue;
  }

  // 标题
  const h = line.match(/^(#{1,6})\s+(.*)$/);
  if (h) {
    const level = h[1].length;
    const text = h[2].trim();
    const id = slug(text);
    if (level === 2 || level === 3) {
      toc.push({ level, id, text: text.replace(/`/g, '') });
    }
    html.push(`<h${level} id="${id}">${inline(text)}</h${level}>`);
    i++;
    continue;
  }

  // 表格
  if (/^\|/.test(line) && /^\|[\s:|-]+\|?\s*$/.test(lines[i + 1] ?? '')) {
    const parseRow = (row) => {
      const cells = row
        .trim()
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .replace(/\\\|/g, '\u0001')
        .split('|')
        .map((c) => c.replace(/\u0001/g, '|').trim());
      return cells;
    };
    const header = parseRow(line);
    i += 2;
    const rows = [];
    while (i < lines.length && /^\|/.test(lines[i])) {
      rows.push(parseRow(lines[i]));
      i++;
    }
    let t = '<div class="tablewrap"><table><thead><tr>';
    t += header.map((c) => `<th>${inline(c)}</th>`).join('');
    t += '</tr></thead><tbody>';
    for (const r of rows) {
      t += '<tr>' + r.map((c) => `<td>${inline(c)}</td>`).join('') + '</tr>';
    }
    t += '</tbody></table></div>';
    html.push(t);
    continue;
  }

  // 引用
  if (/^>\s?/.test(line)) {
    const buf = [];
    while (i < lines.length && /^>\s?/.test(lines[i])) {
      buf.push(lines[i].replace(/^>\s?/, ''));
      i++;
    }
    html.push(`<blockquote>${buf.map((l) => (l ? `<p>${inline(l)}</p>` : '')).join('')}</blockquote>`);
    continue;
  }

  // 列表（支持一层缩进与复选框）
  if (/^\s*([-*+]|\d+\.)\s+/.test(line)) {
    const ordered = /^\s*\d+\./.test(line);
    const items = [];
    let depth = 0;
    while (i < lines.length && /^\s*([-*+]|\d+\.)\s+/.test(lines[i])) {
      const cur = lines[i];
      const indented = /^\s{2,}/.test(cur) || /^\s*\t/.test(cur);
      const text = cur.replace(/^\s*([-*+]|\d+\.)\s+/, '');
      const task = text.match(/^\[( |x|X)\]\s+(.*)$/);
      let body;
      if (task) {
        body = `<span class="task">${task[1].toLowerCase() === 'x' ? '☑' : '☐'}</span> ${inline(task[2])}`;
      } else {
        body = inline(text);
      }
      if (indented) {
        items.push(`<li class="sub">${body}</li>`);
        depth = 1;
      } else {
        items.push(`<li${depth ? ' class="resume"' : ''}>${body}</li>`);
        depth = 0;
      }
      i++;
    }
    const tag = ordered ? 'ol' : 'ul';
    html.push(`<${tag}>${items.join('')}</${tag}>`);
    continue;
  }

  // 段落（连续行合并）
  const buf = [];
  while (
    i < lines.length &&
    !/^\s*$/.test(lines[i]) &&
    !/^```/.test(lines[i]) &&
    !/^#{1,6}\s/.test(lines[i]) &&
    !/^\|/.test(lines[i]) &&
    !/^>\s?/.test(lines[i]) &&
    !/^\s*([-*+]|\d+\.)\s+/.test(lines[i]) &&
    !/^---+\s*$/.test(lines[i])
  ) {
    buf.push(lines[i]);
    i++;
  }
  if (buf.length) {
    html.push(`<p>${inline(buf.join(' '))}</p>`);
  } else {
    i++;
  }
}

/* ------------------------------------------------------------------ *
 * 页面装配
 * ------------------------------------------------------------------ */

const title = '夕梦框架 SunsetReverie · 产品规划文档';

const nav = toc
  .map(
    (t) =>
      `<a class="lvl${t.level}" href="#${t.id}" data-text="${esc(t.text.toLowerCase())}">${esc(t.text)}</a>`,
  )
  .join('\n');

// 从文档头部元信息解析版本与日期，避免模板中硬编码
const versionMatch = raw.match(/\*\*文档版本\*\*：v([0-9.]+)/);
const dateMatch = raw.match(/\*\*(?:最后修订|编写日期)\*\*：([0-9]{4}-[0-9]{2}-[0-9]{2})/);
const docVersion = versionMatch ? 'v' + versionMatch[1] : 'v1.0.0';
const docDate = dateMatch ? dateMatch[1] : '';

const tpl = readFileSync(new URL('./template.html', import.meta.url), 'utf8')
  .replace('{{TITLE}}', () => title)
  .replaceAll('{{VERSION}}', () => docVersion)
  .replaceAll('{{DATE}}', () => docDate)
  .replace('{{NAV}}', () => nav)
  .replace('{{CONTENT}}', () => html.join('\n'));

writeFileSync(outputPath, tpl, 'utf8');
console.log('已生成 ' + outputPath + '（源文件 ' + basename(inputPath) + '，章节数 ' + toc.length + '）');

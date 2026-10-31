import { defineConfig } from 'vitepress'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))

/**
 * 从 Application.php 读版本号，避免文档里硬编码导致和代码不一致。
 *
 * 之前导航栏写死 v1.0.0-beta.1，而代码已经是 beta.2——这种不同步
 * 靠人工记得改是治不好的。
 */
function frameworkVersion(): string {
  try {
    const source = readFileSync(resolve(here, '../../core/Foundation/Application.php'), 'utf-8')
    const matched = source.match(/public const VERSION = '([^']+)'/)

    return matched ? matched[1] : 'unknown'
  } catch {
    return 'unknown'
  }
}

const VERSION = frameworkVersion()

/**
 * 把仓库根的 CHANGELOG.md 同步成文档站的一页。
 *
 * 为什么在构建时生成而不是手工复制：
 * 手工复制会有两份内容，升级时必有一份忘改——这和之前版本号不同步
 * 是同一类问题。同步函数保证只有一份真源。
 *
 * 生成文件在 .gitignore 里，不进仓库。
 */
function syncChangelog(): void {
  try {
    const source = readFileSync(resolve(here, '../../CHANGELOG.md'), 'utf-8')

    // 去掉原文的 `# 更新日志` 标题（下面会重新加）
    const body = source.replace(/^#\s*更新日志\s*\n+/, '').trimStart()

    const page = [
      '---',
      'outline: [2, 3]',
      '---',
      '',
      '# 更新记录',
      '',
      '> 本页由仓库根的 `CHANGELOG.md` 自动生成，请勿直接编辑。',
      '',
      body,
      '',
    ].join('\n')

    writeFileSync(resolve(here, '../handbook/changelog.md'), page, 'utf-8')
  } catch {
    // 生成失败不该阻断构建——缺这一页比构建挂掉好
  }
}

syncChangelog()

/**
 * 夕梦框架使用手册
 *
 * srcDir 设为 `handbook`：Markdown 源放在 docs/handbook/，
 * 但 URL 不带这层前缀（访问 /guide/install.html 而不是 /handbook/guide/install.html）。
 * 这样 docs/ 下的内部文档（product-plan 等）不会被构建进站点。
 */
export default defineConfig({
  srcDir: 'handbook',

  lang: 'zh-CN',
  title: '夕梦',
  titleTemplate: ':title · 夕梦框架',
  description: '零依赖的 PHP 微服务框架 —— 多 API 域隔离、组件可替换、无 ORM',

  cleanUrls: true,
  lastUpdated: true,

  head: [
    ['link', { rel: 'icon', href: '/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#3e3edc' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: '夕梦框架' }],
    ['meta', { property: 'og:description', content: '零依赖的 PHP 微服务框架' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    /*
     * 导航只列**已写完**的页面。
     * 未写的先不放，否则点进去是 404——宁可少一项，也不要死链。
     * 每写完一批，把对应项加回来。
     */
    nav: [
      { text: '指南', link: '/guide/introduction', activeMatch: '/guide/' },
      { text: '升级', link: '/guide/upgrade', activeMatch: '/guide/upgrade' },
      { text: `v${VERSION}`, link: '/changelog' },
    ],

    sidebar: {
      '/guide/': [
        {
          text: '开始',
          items: [
            { text: '介绍', link: '/guide/introduction' },
            { text: '安装', link: '/guide/install' },
            { text: '第一个接口', link: '/guide/first-api' },
          ],
        },
        {
          text: '核心概念',
          items: [
            { text: '路由', link: '/guide/routing' },
            { text: '控制器', link: '/guide/controller' },
            { text: '请求与响应', link: '/guide/request-response' },
            { text: '中间件', link: '/guide/middleware' },
            { text: '领域服务', link: '/guide/domain-service' },
            { text: '代码组织', link: '/guide/structure' },
            { text: '错误处理', link: '/guide/error-handling' },
          ],
        },
        {
          text: '数据库',
          items: [
            { text: '查询构造器', link: '/guide/database' },
            { text: '迁移', link: '/guide/migration' },
            { text: '事务', link: '/guide/transaction' },
            { text: '多数据库', link: '/guide/multi-database' },
          ],
        },
        {
          text: '组件',
          items: [
            { text: '缓存', link: '/guide/cache' },
            { text: '认证', link: '/guide/auth' },
            { text: '校验', link: '/guide/validation' },
            { text: '上传', link: '/guide/upload' },
            { text: '图片', link: '/guide/image' },
            { text: '邮件', link: '/guide/mail' },
            { text: '队列', link: '/guide/queue' },
            { text: '日志', link: '/guide/log' },
            { text: '限流', link: '/guide/ratelimit' },
          ],
        },
        {
          text: '参考',
          items: [
            { text: '命令行', link: '/guide/commands' },
            { text: '门面', link: '/guide/facades' },
            { text: '配置', link: '/guide/config' },
            { text: '错误码', link: '/guide/error-codes' },
            { text: '部署', link: '/guide/deployment' },
          ],
        },
        {
          text: '升级',
          items: [
            { text: '从旧版本升级', link: '/guide/upgrade' },
            { text: '更新记录', link: '/changelog' },
          ],
        },
      ],
    },

    socialLinks: [{ icon: 'github', link: 'https://github.com' }],

    search: {
      provider: 'local',
      options: {
        // 中文需要按字切分，默认的分词器只按空格
        miniSearch: {
          options: {
            tokenize: (text: string) => text.split(/[\s\-_.，。！？、；：（）《》【】]+/u).filter(Boolean),
          },
        },
        translations: {
          button: { buttonText: '搜索文档', buttonAriaLabel: '搜索' },
          modal: {
            noResultsText: '没有找到结果',
            resetButtonTitle: '清除',
            footer: {
              selectText: '选择',
              navigateText: '切换',
              closeText: '关闭',
            },
          },
        },
      },
    },

    outline: { level: [2, 3], label: '本页目录' },

    docFooter: { prev: '上一篇', next: '下一篇' },

    lastUpdated: {
      text: '最后更新',
      formatOptions: { dateStyle: 'medium' },
    },

    darkModeSwitchLabel: '主题',
    returnToTopLabel: '回到顶部',
    sidebarMenuLabel: '目录',

    footer: {
      message: '以 MIT 许可发布 · v1.0 夕照',
      copyright: '日落时分的第一束稳定光',
    },
  },
})

import { defineConfig } from 'vitepress'

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
      { text: 'v1.0.0-beta.1', link: '/' },
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
          text: '升级',
          items: [{ text: '从旧版本升级', link: '/guide/upgrade' }],
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

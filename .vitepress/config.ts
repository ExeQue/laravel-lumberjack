import { defineConfig } from 'vitepress'

// GitHub Pages serves the docs under /laravel-lumberjack/; the lerd docs worker at the root of its subdomain.
const base = process.env.DOCS_BASE ?? '/laravel-lumberjack/'

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
  title: 'Lumberjack for Laravel',
  description: 'Errors, performance, jobs, logs and scheduled tasks from a Laravel app, sent to Lumberjack.',
  base,
  lastUpdated: true,
  cleanUrls: true,

  // Lumberjack's own icons, from public/; head links do not get the base added.
  head: [
    ['link', { rel: 'icon', href: `${base}favicon.ico`, sizes: 'any' }],
    ['link', { rel: 'icon', href: `${base}favicon.svg`, type: 'image/svg+xml' }],
    ['link', { rel: 'apple-touch-icon', href: `${base}apple-touch-icon.png` }],
  ],

  srcExclude: ['vendor/**', 'node_modules/**', 'tests/**', 'workbench/**', 'config/**', 'build/**', 'src/**'],

  // README.md is the front page here, and stays the front page on GitHub.
  rewrites: {
    'README.md': 'index.md',
    'docs/README.md': 'docs/index.md',
  },

  vite: {
    server: {
      // Served through lerd on docs.laravel-lumberjack.test, which Vite would otherwise refuse.
      allowedHosts: ['.test'],
      watch: {
        ignored: ['**/vendor/**', '**/workbench/**', '**/tests/**', '**/.phpunit.cache/**'],
      },
    },
  },

  themeConfig: {
    logo: '/favicon.svg',
    nav: [{ text: 'Documentation', link: '/docs/' }],

    sidebar: [
      {
        text: 'Getting started',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Installation', link: '/docs/installation' },
          { text: 'The agent', link: '/docs/agent' },
          { text: 'Documentation index', link: '/docs/' },
        ],
      },
      {
        text: 'What it sends',
        items: [
          { text: 'What is recorded', link: '/docs/recording' },
          { text: 'Logs', link: '/docs/logs' },
          { text: 'Browser errors', link: '/docs/browser' },
        ],
      },
      {
        text: 'Around it',
        items: [
          { text: 'Health checks', link: '/docs/health-checks' },
          { text: 'Custom alerts', link: '/docs/alerts' },
          { text: 'Reacting to alerts', link: '/docs/webhooks' },
          { text: 'Testing your app', link: '/docs/testing' },
          { text: 'Artisan commands', link: '/docs/commands' },
          { text: 'Configuration', link: '/docs/configuration' },
          { text: 'Laravel Boost', link: '/docs/boost' },
        ],
      },
      {
        text: 'Contributing',
        items: [{ text: 'Contributing', link: '/CONTRIBUTING' }],
      },
    ],

    search: { provider: 'local' },

    socialLinks: [{ icon: 'github', link: 'https://github.com/ExeQue/laravel-lumberjack' }],

    editLink: {
      pattern: 'https://github.com/ExeQue/laravel-lumberjack/edit/main/:path',
      text: 'Edit this page on GitHub',
    },

    outline: [2, 3],
  },
})

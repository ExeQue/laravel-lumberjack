import { defineConfig } from 'vitepress'

// The markdown in this repository is the source: the site is a view of it, not a copy.
// Everything links the same way on GitHub and on the site, so srcDir is the repository root.
export default defineConfig({
  title: 'Lumberjack for Laravel',
  description: 'Errors, performance, jobs, logs and scheduled tasks from a Laravel app, sent to Lumberjack.',
  base: '/laravel-lumberjack/',
  lastUpdated: true,
  cleanUrls: true,

  srcExclude: ['vendor/**', 'node_modules/**', 'tests/**', 'workbench/**', 'config/**', 'build/**', 'src/**'],

  // README.md is the front page here, and stays the front page on GitHub.
  rewrites: {
    'README.md': 'index.md',
    'docs/README.md': 'docs/index.md',
  },

  vite: {
    server: {
      watch: {
        ignored: ['**/vendor/**', '**/workbench/**', '**/tests/**', '**/.phpunit.cache/**'],
      },
    },
  },

  themeConfig: {
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
          { text: 'Reacting to alerts', link: '/docs/webhooks' },
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

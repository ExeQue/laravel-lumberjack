# Browser errors

Put the directive in the `<head>` of your layout:

```blade
@lumberjack
```

It renders a script tag that loads Lumberjack's browser script from your Lumberjack server (`{LUMBERJACK_URL}/sdk/v1.js`, deferred), with:

- the project's public key (`LUMBERJACK_PUBLIC_KEY`; without it, or without `LUMBERJACK_URL`, the directive renders nothing),
- the environment and release,
- the signed-in user's id, only when `LUMBERJACK_BROWSER_USER=true` (see below),
- the request's trace id, so a browser error links to the server request that rendered the page (left out while recording is off).

These are the `data-key`, `data-environment`, `data-release`, `data-user`, `data-trace` and `data-announce` attributes of the script tag; the server's docs describe them for apps that write the tag by hand.

### The signed-in user

The user's id is left out of the page by default: a page cached for everyone (a
full-page cache, a CDN) would serve one user's id to all. Set it from JavaScript, where
it is never cached:

```blade
@auth
    <script>document.addEventListener('DOMContentLoaded', () => window.Lumberjack?.setUser(@json(auth()->id())));</script>
@endauth
```

or, on pages that are never shared-cached, turn the attribute on with
`LUMBERJACK_BROWSER_USER=true` (`browser_user` in the config).

### Content-Security-Policy

With a CSP that uses nonces, the tag needs one. When the app gives Vite a nonce
(`Vite::useCspNonce()`), the tag carries the same one. Otherwise pass yours:

```blade
@lumberjack($cspNonce)
```

The CSP must also allow scripts from your Lumberjack server (`script-src`) and requests
to it (`connect-src`).

The page's origin must be listed under **allowed origins** in the project's settings.

## What it sends

Uncaught errors and unhandled promise rejections, with their stack, the page's URL and the browser. Errors are sent with `navigator.sendBeacon`, so they survive the page being closed.

To keep the noise down the script:

- stays silent for crawlers, link previews and automated browsers, and Lumberjack drops their batches as well,
- skips errors thrown by browser extensions, `Script error.` from cross-origin scripts and `ResizeObserver loop` warnings,
- sends the same error at most 5 times per page view, and at most 100 errors in all.

Browser errors never send alerts: they stay on the issues list.

## From your own code

```js
window.Lumberjack.captureException(error, { cart_id: 42 });
window.Lumberjack.setUser(userId);

// Messages to drop, as regular expressions or substrings.
window.Lumberjack.init({ ignore: [/^AbortError/, 'Load failed'] });
```

## In the console

While the app runs with `APP_DEBUG`, the script says in the console that it is there. Set `LUMBERJACK_ANNOUNCE` to change that either way.

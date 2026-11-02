# Browser errors

Put the directive in the `<head>` of your layout:

```blade
@lumberjack
```

It renders a script tag that loads Lumberjack's browser script from your Lumberjack server, with:

- the project's public key (`LUMBERJACK_PUBLIC_KEY`; without it the directive renders nothing),
- the environment and release,
- the signed-in user's id,
- the request's trace id, so a browser error links to the server request that rendered the page.

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

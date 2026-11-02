<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>lumberjack-laravel</title>
    @lumberjack
    <style>
        :root { --accent: #d97706; --text: #1c1917; --muted: #57534e; --line: #e7e5e4; --surface: #fff; --background: #f5f5f4; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: var(--text); background: var(--background); }
        header { background: var(--surface); border-bottom: 1px solid var(--line); padding: 14px 24px; font-weight: 600; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 24px; display: grid; gap: 20px; }
        section { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 20px; }
        h2 { margin: 0 0 4px; font-size: 16px; }
        p { margin: 0 0 14px; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        button { font: inherit; cursor: pointer; border: 1px solid var(--line); background: var(--surface); border-radius: 7px; padding: 7px 12px; }
        button:hover { border-color: var(--accent); }
        .status { background: #fef6e7; border-color: #f5d9a8; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 0; font-size: 13px; }
        dt { color: var(--muted); }
        dd { margin: 0; font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<header>lumberjack-laravel · demo</header>
<main>
    @if (session('status'))
        <section class="status">{{ session('status') }}</section>
    @endif

    <section>
        <h2>Setup</h2>
        <p>Batches go to the agent (<code>composer agent</code>), which sends them to Lumberjack every 5 seconds.</p>
        <dl>
            <dt>Lumberjack</dt><dd>{{ config('lumberjack.url') }}</dd>
            <dt>Agent</dt><dd>{{ config('lumberjack.agent.host') }}:{{ config('lumberjack.agent.port') }}</dd>
            <dt>Server token</dt><dd>{{ config('lumberjack.token') ? 'set' : 'missing, see .env.example' }}</dd>
            <dt>Public key</dt><dd>{{ config('lumberjack.public_key') ?: 'missing, see .env.example' }}</dd>
            <dt>Trace</dt><dd>{{ app(ExeQue\Lumberjack\Recorder::class)->traceId() }}</dd>
        </dl>
    </section>

    <section>
        <h2>Server</h2>
        <p>Each button records something and comes back here.</p>
        <div class="actions">
            @foreach (['exception' => 'Throw an exception', 'queries' => 'Run 20 queries', 'slow' => 'Slow request', 'log' => 'Write log lines', 'job' => 'Dispatch a job', 'failing-job' => 'Dispatch a failing job'] as $action => $label)
                <form method="post" action="/demo/{{ $action }}">@csrf<button>{{ $label }}</button></form>
            @endforeach
        </div>
    </section>

    <section>
        <h2>Browser</h2>
        <p>Sent straight to Lumberjack by the script from <code>@@lumberjack</code>, with this request's trace id.</p>
        <div class="actions">
            <button onclick="setTimeout(() => { undefinedFunction(); })">Uncaught error</button>
            <button onclick="Promise.reject(new Error('Checkout API answered 500'))">Unhandled rejection</button>
            <button onclick="Lumberjack.captureException(new Error('Handled, but worth knowing'), { cart_id: 42 })">captureException</button>
        </div>
    </section>
</main>
</body>
</html>

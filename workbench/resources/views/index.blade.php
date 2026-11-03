<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>laravel-lumberjack</title>
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
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 4px 8px 4px 0; border-bottom: 1px solid var(--line); }
        th { color: var(--muted); font-weight: 500; }
        .state-ok { color: #15803d; } .state-degraded { color: #b45309; } .state-down { color: #b91c1c; } .state-recovering { color: #1d4ed8; }
        dd { margin: 0; font-family: ui-monospace, monospace; overflow-wrap: anywhere; }
    </style>
</head>
<body>
<header>laravel-lumberjack · demo</header>
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
            <dt>Release</dt><dd>{{ config('lumberjack.release') }}</dd>
            <dt>Trace</dt><dd>{{ app(ExeQue\Lumberjack\Recorder::class)->traceId() }}</dd>
        </dl>
    </section>

    <section>
        <h2>Errors</h2>
        <p>Each becomes an issue. The second groups by signed-in user, the third escalates.</p>
        <div class="actions">
            <form method="post" action="/demo/exception">@csrf<button>Throw an exception</button></form>
            <form method="post" action="/demo/exception-as-user">@csrf<button>Throw as a signed-in user</button></form>
            <form method="post" action="/demo/burst">@csrf<button>Burst of 30 errors</button></form>
            <form method="post" action="/demo/log">@csrf<button>Write log lines</button></form>
        </div>
    </section>

    <section>
        <h2>Performance</h2>
        <p>Requests, queries, spans and status codes for the Routes, Queries and Traces pages.</p>
        <div class="actions">
            <form method="post" action="/demo/queries">@csrf<button>Run 20 queries</button></form>
            <form method="post" action="/demo/slow">@csrf<button>Slow request</button></form>
            <form method="post" action="/demo/spans">@csrf<button>Checkout with cache, API, mail</button></form>
            <form method="post" action="/demo/status/404">@csrf<button>Answer 404</button></form>
            <form method="post" action="/demo/status/422">@csrf<button>Answer 422</button></form>
            <form method="post" action="/demo/status/503">@csrf<button>Answer 503</button></form>
        </div>
    </section>

    <section>
        <h2>Jobs, queues and commands</h2>
        <p>Jobs run in the queue worker. The bulk queue has no worker, so flooding it builds a backlog.</p>
        <div class="actions">
            <form method="post" action="/demo/job">@csrf<button>Dispatch a job</button></form>
            <form method="post" action="/demo/failing-job">@csrf<button>Dispatch a failing job</button></form>
            <form method="post" action="/demo/queue/flood">@csrf<button>Flood the bulk queue</button></form>
            <form method="post" action="/demo/queue/drain">@csrf<button>Drain the bulk queue</button></form>
            <form method="post" action="/demo/command">@csrf<button>Run an artisan command</button></form>
        </div>
    </section>

    <section>
        <h2>Releases</h2>
        <p>Writes a new release, as a deploy would. Later requests and errors carry it.</p>
        <div class="actions">
            <form method="post" action="/demo/release">@csrf<button>Deploy a new release</button></form>
        </div>
    </section>

    <section>
        <h2>Health</h2>
        <p>database, cache, disk:local and payments-api run every minute from the scheduler. payments-api fails while it is broken.</p>
        <div class="actions">
            <form method="post" action="/demo/health/break">@csrf<button>Break payments-api</button></form>
            <form method="post" action="/demo/health/fix">@csrf<button>Fix payments-api</button></form>
            <form method="post" action="/demo/health/run">@csrf<button>Run payments-api now</button></form>
            <form method="post" action="/demo/health/report">@csrf<button>Report a failed disk read</button></form>
        </div>
        <p style="margin-top: 14px">As Lumberjack says they stand, from <code>Lumberjack::healthStates()</code>. Each change is logged to the Logs page.</p>
        <table>
            <tr><th>Slug</th><th>State</th><th>Since</th></tr>
            @forelse (ExeQue\Lumberjack\Facades\Lumberjack::healthStates() as $snapshot)
                <tr><td>{{ $snapshot->slug }}</td><td class="state-{{ $snapshot->state->value }}">{{ $snapshot->state->value }}</td><td>{{ $snapshot->since?->diffForHumans() ?? '' }}</td></tr>
            @empty
                <tr><td colspan="3">No states yet: is the agent running, and does Lumberjack answer?</td></tr>
            @endforelse
        </table>
    </section>

    <section>
        <h2>Browser</h2>
        <p>Sent straight to Lumberjack by the script from <code>@@lumberjack</code>, with this request's trace id. Web vitals for this page are sent when you leave it or switch tabs.</p>
        <div class="actions">
            <button onclick="setTimeout(() => { undefinedFunction(); })">Uncaught error</button>
            <button onclick="Promise.reject(new Error('Checkout API answered 500'))">Unhandled rejection</button>
            <button onclick="Lumberjack.captureException(new Error('Handled, but worth knowing'), { cart_id: 42 })">captureException</button>
        </div>
    </section>
</main>
</body>
</html>

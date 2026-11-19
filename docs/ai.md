<!-- Part of the Lumberjack for Laravel documentation - see ../README.md -->

# AI token usage

Lumberjack records the token usage of every AI call your app makes: the provider, the
model, the tokens, which API key was used, whose key it is and who caused the call. The
server works out the cost per key and per actor from it, so you can see what each key,
tenant and user costs.

What is sent for each call, as an `ai_call` event:

- the source (`laravel-ai`, `prism` or `manual`), the provider, the driver and the model
  that answered
- the operation: `text`, `structured`, `stream`, `embeddings`, `image`, `audio`,
  `transcription` or `rerank`
- the tokens: input (every input token, cached ones included), output (reasoning
  included), cache reads, cache writes and reasoning; a count that was not reported is
  null, except with Laravel AI 0.x, which reports a missing cache or reasoning count as 0
- the duration, the status (`ok` or `error`) and the exception's class for a failed call
- the key: a fingerprint of it (never the key), its label and its owner
- the actor, and for Laravel AI the invocation id and step

Prompts and responses are never sent.

## Setting it up

1. Install the SDK as described in [Installation](installation.md). Token tracking needs
   Lumberjack server 0.13.0 or newer.
2. Use [Laravel AI](https://github.com/laravel/ai) (0.11 or 1.x) or
   [Prism](https://github.com/prism-php/prism) (0.100 to 0.103). Lumberjack finds them on
   its own: nothing to register, nothing to change in your calls. Neither is a dependency
   of the SDK; install the one you use.
3. That is it. Every call through them is now recorded with the rest of the request or
   job. For calls made with any other client, see [Recording a call by hand](#recording-a-call-by-hand).

With Laravel AI, each provider call is recorded: every step of an agent (a tool loop is
several steps), streamed or not, and embeddings, images, audio, transcriptions, reranking
and classification. A provider that fails and fails over to the next one is recorded as a
failed call, then the call that answered. With Prism, every `text`, `structured`,
`embeddings`, `images`, `textToSpeech`, `speechToText` and stream call is recorded. A
stream is recorded when it ends, also when the code stops reading it early.

A Laravel AI stream that the code stops reading early (or a client that disconnects)
never reports its tokens. Such a step is recorded as a `stream` call (or `text` or
`structured`, for a step that was not streamed) with null tokens and status `ok`, in the
scope it was made in, when the agent is done, the request ends or the
queued job ends (or right away, when more than 100 calls are left open). On Laravel AI
0.x, which has no events for failed embeddings, images, audio, transcription and
reranking, such a call that never finished and did not fail over is recorded then as an
error, `Unfinished`.

Prism is recorded only in the versions this SDK was tested with, 0.100 to 0.103: a newer
Prism may add provider methods the wrapper does not know. With a newer (or unknown)
version, Prism calls are not recorded and Lumberjack says so once, as an error log in
Lumberjack and in PHP's error log. Update `exeque/laravel-lumberjack`, or record the calls
with [`Lumberjack::aiUsage()`](#recording-a-call-by-hand) until then.

To record Prism calls, Lumberjack wraps the providers Prism resolves. Code that checks a
resolved provider with `instanceof` sees the wrapper; its `inner()` returns the provider
it wraps.

Each client counts tokens its own way per provider, such as Anthropic leaving cached
tokens out of the input. Lumberjack normalizes them, so `input` always includes cached
tokens and `output` always includes reasoning.

## Configuration

```php
// config/lumberjack.php
'ai' => [
    'enabled' => (bool) env('LUMBERJACK_AI', true),
    'laravel_ai' => true,
    'prism' => true,
],
```

| Key             | Env             | Default |                                       |
| --------------- | --------------- | ------- | ------------------------------------- |
| `ai.enabled`    | `LUMBERJACK_AI` | `true`  | `false` records no AI calls at all    |
| `ai.laravel_ai` |                 | `true`  | Whether Laravel AI calls are recorded |
| `ai.prism`      |                 | `true`  | Whether Prism calls are recorded      |

`LUMBERJACK_ENABLED=false` turns this off too, like everything else.

### Always recorded, never sampled

There is no sample rate for AI calls. Every call is recorded, also in a request or job that
the `requests.sample_rate` or `jobs.sample_rate` left out. AI usage is accounting: a
sampled bill is a wrong bill.

## The key: only a fingerprint

The API key never leaves your app. Lumberjack sends `sha256("driver:key")`, such as
`sha256("openai:sk-...")`, where the driver is the vendor in lowercase (`openai`,
`anthropic`, `gemini`): Laravel AI's driver, or Prism's built-in provider name. A provider added to
Prism with `extend()` is hashed under the name it was added with. The fingerprint is worked out the moment the call is recorded, and the server
keeps only a keyed hash of that. The same key gives the same fingerprint every time, so
Lumberjack can tell your keys apart and add up what each one costs without ever knowing
them.

Laravel AI's key comes from the provider in `config/ai.php`; Prism's from
`config/prism.php`, or from `usingProviderConfig(['api_key' => ...])` when the code
passes one.

## Labels, owners and actors

A key can carry a **label** (`prod-primary`, `backup`, `byok`) and an **owner**: whose key
it is, such as a tenant bringing its own. Each call has an **actor**: who caused it. A
Laravel AI agent can say all three itself; around any other code, wrap the calls in
[`Lumberjack::ai()`](#bring-your-own-key).

### Agents that know their owner

A Laravel AI agent that knows who its calls are for can say so itself, with no
`Lumberjack::ai()` around it. Implement only what the agent knows:

- `ExeQue\Lumberjack\Contracts\HasLumberjackAiOwner`: `lumberjackAiOwner()`, who pays
  (the owner of the key).
- `ExeQue\Lumberjack\Contracts\HasLumberjackAiActor`: `lumberjackAiActor()`, who the calls
  are made for.
- `ExeQue\Lumberjack\Contracts\HasLumberjackAiLabel`: `lumberjackAiLabel()`, the key's
  label.

Owner and actor take a model, an object implementing `ActsAsLumberjackActor` or an
`Actor`; null leaves that one to the scopes around the agent:

```php
use ExeQue\Lumberjack\Contracts\HasLumberjackAiLabel;
use ExeQue\Lumberjack\Contracts\HasLumberjackAiOwner;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class SupportAgent implements Agent, HasLumberjackAiOwner, HasLumberjackAiLabel
{
    use Promptable;

    public function __construct(private Team $team) {}

    public function lumberjackAiOwner(): Team
    {
        return $this->team;
    }

    public function lumberjackAiLabel(): ?string
    {
        return $this->team->openai_key !== null ? 'byok' : null;
    }

    // instructions() ...
}
```

They are read once when the agent's run starts, so keep them cheap (no queries the
agent has not made already). They count for the agent's own steps, streamed or not, over
any `Lumberjack::ai()` around it, and for the AI calls made while it runs: its tools'
(embeddings, a Prism call, `aiUsage()`), a sub-agent that implements none of them, and on
Laravel AI 1.x the conversation title. A `Lumberjack::ai()` a tool opens still wins for
the calls inside it, and a sub-agent with its own uses those for its steps. A stream read
only in part keeps what the agent said when it started. A method that throws, or an owner
or actor that cannot be told (its `toLumberjackActor()` throws), is left out for that
call (and said once in the logs); the call is still recorded.

### The actor

While a request is served, the actor is the logged in user, when it is a model or
implements `ActsAsLumberjackActor` (see
[Activity](activity.md#an-actor-that-says-who-it-is)). A queued job or a command has no
default actor, as a user logged in there may be left from an earlier job. Set one with
`by:`, such as in a job that acts for a user:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;

Lumberjack::ai(fn () => SummarizeAgent::make()->prompt($document->text), by: $document->owner);
```

An actor is sent as its type and key only, like activity actors.

### Bring your own key

When a tenant uses its own key, wrap the calls in `Lumberjack::ai()` with the tenant as
the owner. The callback's result is returned:

```php
$reply = Lumberjack::ai(
    fn () => Prism::text()
        ->using('openai', 'gpt-5')
        ->usingProviderConfig(['api_key' => $team->openai_key])
        ->withPrompt($prompt)
        ->asText(),
    owner: $team,
    label: 'byok',
);
```

`by:`, `owner:` and `label:` take a model, an object implementing
`ActsAsLumberjackActor`, or an `ExeQue\Lumberjack\Activity\Actor` such as
`new Actor('tenant', 42)`. Scopes nest: an inner `Lumberjack::ai()` overrides only what it
sets. A scope ends when its callback returns or throws, so none outlives its own code,
also in Octane and queue workers. Scopes are one stack per process: they are not fiber-safe.

A stream is read after the callback has returned, so its calls keep the scope they were
started in: a generator (Prism's `asStream()`) comes back wrapped so it runs inside the
scope, a streamed HTTP response (Prism's `asEventStreamResponse()` and
`asDataStreamResponse()`, or your own `response()->stream()`) runs its callback in the
scope when it is sent, and Laravel AI's streamable response keeps its type and is tied to
the scope by its invocation id. Return the stream from the callback to keep this:

```php
return Lumberjack::ai(
    fn () => SupportAgent::make()->stream($message),
    owner: $team,
    label: 'byok',
);
```

A stream keeps the scope of the innermost `Lumberjack::ai()` it came back through, over
any scope around the code that reads it. AI calls a Laravel AI agent's tools make while
it runs (a sub-agent, embeddings, a Prism call, `aiUsage()`), and on Laravel AI 1.x a
conversation title generated after it, keep the agent's scope too, unless the tool opens a `Lumberjack::ai()`
of its own: that one wins for what it sets, such as for a call the platform pays for
inside a tenant's run, and the agent's scope still counts for the rest.

A Laravel AI stream's tie to its scope lasts until the next request (Octane included) or
the next queued job. Lumberjack remembers 100 agent runs at a time, in a request too: past
that it forgets the least recently used run that is done, never one still running or a
stream not read to the end, unless more than 100 are. Even then, a step already under way
keeps the scope it started in.

On Laravel AI 0.11 the conversation title is generated outside the agent's run and is not
recorded at all. Turn it off with `ai.conversations.generate_title`, or record it with
[`Lumberjack::aiUsage()`](#recording-a-call-by-hand).

### More than one key

To label keys, or to work out the owner or actor per call, give a resolver. It gets each
`ExeQue\Lumberjack\Ai\AiCall` and returns any of `by`, `owner` and `label`, or null to
leave the call as it is. `usesKey()` tells which key the call was made with, without the
key being kept anywhere:

```php
use ExeQue\Lumberjack\Ai\AiCall;
use ExeQue\Lumberjack\Facades\Lumberjack;

// In a service provider's boot()
Lumberjack::resolveAiKeyUsing(fn (AiCall $call): ?array => match (true) {
    $call->usesKey(config('ai.providers.openai.key')) => ['label' => 'prod-primary'],
    $call->usesKey(config('services.openai.backup_key')) => ['label' => 'backup'],
    default => null,
});
```

What wins, first to last: what `aiUsage()` is given; the resolver; for an agent's own
steps, [what the agent says](#agents-that-know-their-owner); a `Lumberjack::ai()` opened
inside the agent's run (by its tools or sub-agents); what the agent and the agents around
it say and the scope of the stream the call belongs to (inner over outer); the
`Lumberjack::ai()` scopes around the code reading it, innermost first; and (for the actor)
the logged in user of the request. Where or when the stream is read does not change this.
Each counts only for what it sets. A resolver that throws is left out for that call (and
said once in the logs); the call is still recorded.

A scope does not travel with a queued agent: `Lumberjack::ai(fn () => $agent->queue(...))`
does not reach the job that runs it. Give the agent the
[contracts](#agents-that-know-their-owner) instead, as they go with it.

## Recording a call by hand

For a provider called with its own SDK or plain HTTP, record the call yourself:

```php
Lumberjack::aiUsage(
    provider: 'anthropic',
    model: $response['model'],
    input: $usage['input_tokens'] + $usage['cache_read_input_tokens'] + $usage['cache_creation_input_tokens'],
    output: $usage['output_tokens'],
    cacheRead: $usage['cache_read_input_tokens'],
    cacheWrite: $usage['cache_creation_input_tokens'],
    key: config('services.anthropic.key'),
    operation: 'text',
    durationMs: $milliseconds,
);
```

`input` is every input token, cached ones included, and `output` every output token,
reasoning included; pass them that way, they are sent as given. Also: `reasoning`,
`driver` (defaults to the provider), `error` (the exception's class, for a failed call),
`by`, `owner` and `label`. The key is only fingerprinted. `aiUsage()` never throws.

## Testing

Under `Lumberjack::fake()`, AI calls can be asserted on, and calls made through
`Prism::fake()` or a faked Laravel AI agent are recorded like real ones:

```php
use ExeQue\Lumberjack\Facades\Lumberjack;
use ExeQue\Lumberjack\Testing\RecordedAiCall;

Lumberjack::fake();
Prism::fake([TextResponseFake::make()]);

// run the code under test

Lumberjack::assertAiCallRecorded('gpt-5', provider: 'openai', times: 1);
Lumberjack::assertAiCallRecorded(fn (RecordedAiCall $call) => $call->isOwnedBy('team', $team->id) && $call->usesKey($team->openai_key));
Lumberjack::assertAiCallNotRecorded(provider: 'anthropic');
```

See [Testing your app](testing.md#ai-calls) for everything a `RecordedAiCall` has.

## Turning it off

`LUMBERJACK_AI=false` records no AI calls. To leave out one client, such as when you record
its calls by hand, set `ai.laravel_ai` or `ai.prism` to `false`.

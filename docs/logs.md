# Logs

Send log lines to Lumberjack with a channel that uses the `lumberjack` driver:

```php
// config/logging.php
'payments' => [
    'driver' => 'lumberjack',
    'channel' => 'payments',   // the log channel in Lumberjack; leave it out for "default"
    'level' => 'info',
],
```

```php
Log::channel('payments')->warning('Refund took {seconds} seconds', ['seconds' => 12]);
```

Without `level` a channel sends everything from `debug` up.

To send everything the app logs, define a channel for it and add it to your `stack`:

```php
'lumberjack' => [
    'driver' => 'lumberjack',
],

'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'lumberjack'],
],
```

- A line goes to the Lumberjack log channel named in the config. A name the project has no channel for lands in `default`, marked with the name it was sent with.
- Lines at `error` and above become issues in Lumberjack.
- An exception that was reported is not sent again as a log line.
- A line's context goes with it, scrubbed in the app (see [Scrubbing](recording.md#scrubbing)), and so does its trace id: Lumberjack shows it on the request or job that wrote it.

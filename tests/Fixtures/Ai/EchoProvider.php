<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ai;

use Prism\Prism\Providers\Provider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * A custom Prism provider, registered with extend().
 */
class EchoProvider extends Provider
{
    public function text(Request $request): Response
    {
        return TextResponseFake::make()->withText($request->prompt() ?? '')->withUsage(new Usage(3, 3))->withMeta(new Meta('echo-1', $request->model()));
    }
}

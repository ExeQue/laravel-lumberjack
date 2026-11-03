<?php

declare(strict_types=1);

use ExeQue\Lumberjack\Release\GitReleaseResolver;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/lumberjack-git-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/.git/refs/heads', recursive: true);
    $this->sha = str_repeat('a1b2c3d4e5', 4);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->root)));

describe('the git release', function () {
    it('is the commit of the checked out branch', function () {
        file_put_contents($this->root.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root.'/.git/refs/heads/main', $this->sha."\n");

        expect((new GitReleaseResolver($this->root))->resolve())->toBe($this->sha);
    });

    it('reads a packed ref', function () {
        file_put_contents($this->root.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->root.'/.git/packed-refs', "# pack-refs with: peeled\n{$this->sha} refs/heads/main\n");

        expect((new GitReleaseResolver($this->root))->resolve())->toBe($this->sha);
    });

    it('is the commit of a detached HEAD', function () {
        file_put_contents($this->root.'/.git/HEAD', $this->sha."\n");

        expect((new GitReleaseResolver($this->root))->resolve())->toBe($this->sha);
    });

    it('is nothing without a repository', function () {
        expect((new GitReleaseResolver(sys_get_temp_dir().'/nowhere-'.bin2hex(random_bytes(4))))->resolve())->toBeNull();
    });
});

describe('the configured release', function () {
    it('comes from LUMBERJACK_RELEASE before the resolver', function () {
        config(['lumberjack.release' => 'v1.2.3']);
        app()->forgetInstance('lumberjack.release');

        expect(app('lumberjack.release'))->toBe('v1.2.3');
    });

    it('comes from the resolver when LUMBERJACK_RELEASE is empty', function () {
        config(['lumberjack.release' => null, 'lumberjack.release_resolver' => FixedRelease::class]);
        app()->forgetInstance('lumberjack.release');

        expect(app('lumberjack.release'))->toBe('from-the-deploy');
    });
});

final class FixedRelease implements ExeQue\Lumberjack\Contracts\ReleaseResolver
{
    public function resolve(): ?string
    {
        return 'from-the-deploy';
    }
}

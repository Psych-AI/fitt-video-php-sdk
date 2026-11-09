<?php

use Fitt\Video\Version;

test('the SDK version matches the version the root composer.json pins for the path package', function () {
    $root = json_decode((string) file_get_contents(dirname(__DIR__, 4).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $paths = array_values(array_filter($root['repositories'] ?? [], fn (array $repository) => ($repository['url'] ?? null) === 'packages/php-sdk'));

    expect(Version::VERSION)->toBe('1.0.0')
        ->and($paths)->toHaveCount(1)
        ->and($paths[0]['type'])->toBe('path')
        ->and($paths[0]['options']['symlink'])->toBeTrue()
        ->and($paths[0]['options']['versions']['fitt/video-sdk'])->toBe(Version::VERSION)
        ->and($root['require']['fitt/video-sdk'])->toBe('^1.0');
});

test('the package depends only on the PSR HTTP interfaces and carries no version of its own', function () {
    $package = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($package['name'])->toBe('fitt/video-sdk')
        ->and($package)->not->toHaveKey('version')
        ->and(array_keys($package['require']))->toBe(['php', 'ext-json', 'psr/http-client', 'psr/http-factory', 'psr/http-message'])
        ->and($package['autoload']['psr-4'])->toBe(['Fitt\\Video\\' => 'src/'])
        ->and($package['extra']['laravel']['providers'])->toBe(['Fitt\\Video\\Laravel\\FittVideoServiceProvider']);
});

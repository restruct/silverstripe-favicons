<?php

namespace Restruct\Favicons\Tests;

use Psr\SimpleCache\CacheInterface;
use Restruct\Favicons\FaviconService;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;

/**
 * Puts the module config back to its shipped defaults for a test, plus a PNG fixture as source.
 *
 * The suite runs inside a host project, and the host's own favicon config (an extra_paths alias, a
 * theme_color, allow_upload: true) applies to the tests too. Without this, a test that asserts a
 * default would pass in CI and fail in any project that configured the module.
 */
trait PinsFaviconConfig
{
    protected function pinFaviconConfig(): void
    {
        $defaults = [
            'source' => __DIR__ . '/fixtures/icon.png',
            'allow_upload' => false,
            'upload_folder' => 'favicon',
            'background_color' => '#ffffff',
            'touch_icon_padding' => 0,
            'maskable_padding' => 10,
            'ico_sizes' => [16, 32, 48],
            'touch_sizes' => [120, 152, 167, 180],
            'manifest' => true,
            'manifest_fields' => [
                'name' => null,
                'short_name' => null,
                'start_url' => null,
                'display' => 'browser',
                'background_color' => null,
                'theme_color' => null,
            ],
            'theme_color' => null,
            'extra_paths' => [],
            'disabled_paths' => [],
            'frontend_tags' => true,
            'admin_tags' => true,
            'security_tags' => true,
            'max_age' => 86400,
            'versioned_max_age' => 31536000,
            'failure_ttl' => 600,
            'use_admintweaks' => true,
            'max_source_size' => 2048,
            'busy_ttl' => 120,
            'asset_folder' => '_favicons',
            # Not the shipped default (true): a test must never write into the webroot of the site
            # it runs in. PublicFileWriterTest switches it on with a temp folder as webroot.
            'write_public_files' => false,
            'write_public_files_in_dev' => false,
        ];
        foreach ($defaults as $key => $value) {
            # remove() first: set() on an array merges with what the host configured
            Config::modify()->remove(FaviconService::class, $key);
            Config::modify()->set(FaviconService::class, $key, $value);
        }
        # Expected URLs are written as /favicon.ico etc.; a host with SS_BASE_URL in a subdirectory
        # (or a different host name) must not change them.
        Config::modify()->set(Director::class, 'alternate_base_url', '/');

        # An in-memory pool per test instead of the site's TEMP_PATH pool: a failure marker or the
        # registry of generated sets from one test must not decide the next one, and the tests
        # must not touch (or clear) the registry of the site they run in. The Injector is nested
        # per test, so the real service comes back afterwards.
        Injector::inst()->registerService(new Psr16Cache(new ArrayAdapter()), CacheInterface::class . '.RestructFavicons');
    }
}

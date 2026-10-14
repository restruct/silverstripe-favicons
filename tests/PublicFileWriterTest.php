<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\PublicFileWriter;
use Restruct\Favicons\Tests\Stub\FavEnvWriter;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The copies of the root-path files in the webroot. Every test writes into its own temp folder,
 * registered as the webroot; the site's real public folder is never touched.
 */
class PublicFileWriterTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    /** Every file written with the default config */
    private const ALL = [
        'favicon.ico',
        'apple-touch-icon.png',
        'apple-touch-icon-precomposed.png',
        'apple-touch-icon-120x120.png',
        'apple-touch-icon-152x152.png',
        'apple-touch-icon-167x167.png',
        'apple-touch-icon-180x180.png',
        'icon-192.png',
        'icon-512.png',
        'icon-maskable-512.png',
        'site.webmanifest',
        'manifest.json',
    ];

    private string $webroot = '';

    private FavEnvWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('PublicFileWriterTest');
        $this->pinFaviconConfig();
        Config::modify()->set(FaviconService::class, 'write_public_files', true);
        # The one SiteConfig record, as every real site has, before anything reads it: created
        # lazily inside a sync, get_one()'s cached "none" would make the test create a second one.
        SiteConfig::current_site_config();
        $this->webroot = TEMP_PATH . '/favicons-webroot-' . bin2hex(random_bytes(4));
        mkdir($this->webroot);
        # Live mode by default, whatever the host runs in (CI and local hosts run in dev).
        $this->writer = new FavEnvWriter();
        $this->writer->setPublicFolder($this->webroot);
        # The SiteConfig hooks fetch the writer through the Injector; nested per test.
        Injector::inst()->registerService($this->writer, PublicFileWriter::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->webroot)) {
            chmod($this->webroot, 0755);
            foreach (scandir($this->webroot) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $path = $this->webroot . '/' . $name;
                    is_dir($path) ? rmdir($path) : unlink($path);
                }
            }
            rmdir($this->webroot);
        }
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function service(): FaviconService
    {
        return FaviconService::singleton();
    }

    /**
     * The bytes the route serves for a variant.
     */
    private function served(string $variant): string
    {
        $data = $this->service()->openVariant($variant);
        $this->assertNotNull($data, $variant . ' is served');
        if (isset($data['stream'])) {
            $body = (string) stream_get_contents($data['stream']);
            fclose($data['stream']);
            return $body;
        }
        return (string) $data['body'];
    }

    /**
     * Names in the webroot, sorted.
     *
     * @return string[]
     */
    private function listing(): array
    {
        $names = array_values(array_diff(scandir($this->webroot), ['.', '..']));
        sort($names);
        return $names;
    }

    private function file(string $name): string
    {
        return (string) file_get_contents($this->webroot . '/' . $name);
    }

    /**
     * Generate the set (as dev/build does first), then sync.
     *
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $this->service()->warmUp();
        return $this->writer->sync();
    }

    /**
     * path => [mtime, inode] of every file in the webroot, to prove a sync did not touch them.
     *
     * @return array<string, array{0:int, 1:int}>
     */
    private function stats(): array
    {
        clearstatcache();
        $stats = [];
        foreach ($this->listing() as $name) {
            $stat = stat($this->webroot . '/' . $name);
            $stats[$name] = [$stat['mtime'], $stat['ino']];
        }
        return $stats;
    }

    public function testEveryRootPathIsWrittenWithTheServedBytes()
    {
        $result = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_SYNCED, $result['status']);
        $this->assertEqualsCanonicalizing(self::ALL, $result['written']);
        $expected = self::ALL;
        sort($expected);
        $this->assertSame($expected, $this->listing(), 'exactly these files, no temp files or folders');

        $service = $this->service();
        foreach (self::ALL as $path) {
            $this->assertSame($this->served((string) $service->resolveRequestPath($path)), $this->file($path), $path);
            $this->assertSame('0644', substr(sprintf('%o', fileperms($this->webroot . '/' . $path)), -4), $path . ' is world-readable');
        }
        $this->assertSame($this->file('site.webmanifest'), $this->file('manifest.json'));
        $this->assertSame($this->file('apple-touch-icon.png'), $this->file('apple-touch-icon-180x180.png'));

        # A second dev/build with nothing changed writes, renames and even reads nothing
        $stats = $this->stats();
        $this->writer->reads = [];
        $again = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_UNCHANGED, $again['status']);
        $this->assertSame([], $again['written']);
        $this->assertSame([], $again['renamed']);
        $this->assertSame([], $this->writer->reads, 'no reads from the store');
        $this->assertEqualsCanonicalizing(self::ALL, $again['current']);
        $this->assertSame($stats, $this->stats(), 'files untouched');
        $this->assertStringContainsString('12 up to date', (string) $this->writer->describe($again));
    }

    public function testAForeignFileIsRenamedInPlaceWithABackup()
    {
        file_put_contents($this->webroot . '/favicon.ico', 'skeleton icon');
        $result = $this->build();
        $backup = 'favicon.bu-' . date('Ymd') . '.ico';
        $this->assertSame(['favicon.ico' => $backup], $result['renamed']);
        $this->assertSame('skeleton icon', $this->file($backup), 'the original is kept, renamed');
        $this->assertSame($this->served('favicon.ico'), $this->file('favicon.ico'));
        $line = (string) $this->writer->describe($result);
        $this->assertStringContainsString('renamed the existing favicon.ico to ' . $backup, $line);
        $this->assertStringContainsString('remove favicon.ico from your project', $line);

        # The path is the module's now: the file coming back (a pull, a hand copy) is overwritten
        # without another backup, so backups never accumulate
        unlink($this->webroot . '/favicon.ico');
        file_put_contents($this->webroot . '/favicon.ico', 'skeleton icon');
        $result = $this->writer->sync();
        $this->assertSame([], $result['renamed']);
        $this->assertSame(['favicon.ico'], $result['written']);
        $this->assertSame($this->served('favicon.ico'), $this->file('favicon.ico'));
        $this->assertSame([$backup], array_values(preg_grep('/\.bu-/', $this->listing())));

        # A backup name already taken gets a counter (another foreign file at a new alias path)
        $this->assertSame('favicon.bu-' . date('Ymd') . '-2.ico', $this->writer->backupName($this->webroot, 'favicon.ico'));

        # No backup folder anywhere: backups sit next to the original
        foreach ($this->listing() as $name) {
            $this->assertFalse(is_dir($this->webroot . '/' . $name), $name . ' is not a folder');
        }
    }

    public function testAnIdenticalFileIsKeptWithoutABackup()
    {
        $this->service()->warmUp();
        file_put_contents($this->webroot . '/favicon.ico', $this->served('favicon.ico'));
        $result = $this->writer->sync();
        $this->assertSame([], $result['renamed']);
        $this->assertContains('favicon.ico', $result['current']);
        $this->assertNotContains('favicon.ico', $result['written']);
        $this->assertSame([], preg_grep('/\.bu-/', $this->listing()));

        # Adopted: when the icon changes it is replaced like the module's own files
        Config::modify()->set(FaviconService::class, 'source', __DIR__ . '/fixtures/icon2.png');
        $result = $this->build();
        $this->assertSame([], $result['renamed']);
        $this->assertSame($this->served('favicon.ico'), $this->file('favicon.ico'));
    }

    public function testOwnFilesAreReplacedWithoutBackupWhenTheIconChanges()
    {
        $this->build();
        $before = $this->file('icon-192.png');

        Config::modify()->set(FaviconService::class, 'source', __DIR__ . '/fixtures/icon2.png');
        $result = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_SYNCED, $result['status']);
        $this->assertSame([], $result['renamed'], 'no backup of the module\'s own files');
        $this->assertEqualsCanonicalizing(self::ALL, $result['written'], 'a new set rewrites every file');
        $this->assertNotSame($before, $this->file('icon-192.png'));
        foreach (self::ALL as $path) {
            $this->assertSame($this->served((string) $this->service()->resolveRequestPath($path)), $this->file($path), $path);
        }
        $this->assertSame([], preg_grep('/\.bu-/', $this->listing()));

        # Exactly once: the next dev/build finds every file current
        $again = $this->build();
        $this->assertSame([], $again['written']);
        $this->assertEqualsCanonicalizing(self::ALL, $again['current']);
    }

    public function testATitleChangeRewritesOnlyTheManifest()
    {
        $this->build();
        $config = SiteConfig::current_site_config();
        $config->Title = 'Another Title';
        # As Settings saves (SS5 SiteConfigLeftAndMain; SS6 versioned's recursive publish handler)
        $config->write();
        $config->publishRecursive();
        # The Settings save already synced; the dev/build after it has nothing left to do
        $this->assertSame([], $this->build()['written']);
        $this->assertStringContainsString('Another Title', $this->file('manifest.json'));

        $config->Title = 'Third Title';
        $this->writer->reads = [];
        $config->write();
        $config->publishRecursive();
        $this->assertSame(['site.webmanifest'], $this->writer->reads, 'only the manifest is read, once for both names');
    }

    public function testFilesAreRemovedWhenTheSourceGoesButNotWhenChangedByHand()
    {
        $this->build();
        file_put_contents($this->webroot . '/icon-192.png', 'replaced by hand');

        Config::modify()->set(FaviconService::class, 'source', null);
        $result = $this->writer->sync();
        $expected = array_values(array_diff(self::ALL, ['icon-192.png']));
        $this->assertEqualsCanonicalizing($expected, $result['removed'], 'stale icons are not served ahead of the route');
        $this->assertSame(['icon-192.png'], $this->listing(), 'a file changed since it was written is not ours any more');
        $this->assertStringContainsString('removed favicon.ico', (string) $this->writer->describe($result));
    }

    public function testADisabledPathIsRemovedAndLeftAlone()
    {
        $this->build();
        Config::modify()->set(FaviconService::class, 'disabled_paths', ['manifest.json', 'favicon.ico']);
        $result = $this->writer->sync();
        $this->assertEqualsCanonicalizing(['manifest.json', 'favicon.ico'], $result['removed']);
        $this->assertFileDoesNotExist($this->webroot . '/manifest.json');
        $this->assertFileExists($this->webroot . '/site.webmanifest', 'only the disabled alias goes');

        # The project's own file at a disabled path is not ours to touch
        file_put_contents($this->webroot . '/manifest.json', '{"name":"project"}');
        $result = $this->writer->sync();
        $this->assertSame([], $result['renamed']);
        $this->assertNotContains('manifest.json', $result['written']);
        $this->assertSame('{"name":"project"}', $this->file('manifest.json'));
    }

    public function testAnUnwritableWebrootFallsBackToTheRoute()
    {
        chmod($this->webroot, 0555);
        clearstatcache();
        if (is_writable($this->webroot)) {
            # Running as root, which writes regardless of the mode
            $this->markTestSkipped('the folder stays writable for this user');
        }
        $result = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_UNWRITABLE, $result['status']);
        $this->assertSame([], $this->listing());
        $this->assertStringContainsString('is not writable', (string) $this->writer->describe($result));
        $this->assertStringStartsWith("\0\0\1\0", $this->served('favicon.ico'), 'the route still answers');
    }

    public function testNothingIsWrittenInDevModeUnlessSwitchedOn()
    {
        $this->writer->dev = true;
        $result = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_DEV, $result['status']);
        $this->assertSame([], $this->listing());
        $this->assertNull($this->writer->describe($result), 'no dev/build line in dev');

        Config::modify()->set(FaviconService::class, 'write_public_files_in_dev', true);
        $result = $this->writer->sync();
        $this->assertEqualsCanonicalizing(self::ALL, $result['written']);
    }

    public function testSwitchedOffWritesNothingAndRemovesOwnFiles()
    {
        Config::modify()->set(FaviconService::class, 'write_public_files', false);
        $result = $this->build();
        $this->assertSame(PublicFileWriter::STATUS_OFF, $result['status']);
        $this->assertSame([], $this->listing());

        Config::modify()->set(FaviconService::class, 'write_public_files', true);
        $this->writer->sync();
        file_put_contents($this->webroot . '/favicon.ico', 'edited by hand');

        Config::modify()->set(FaviconService::class, 'write_public_files', false);
        $result = $this->writer->sync();
        $this->assertSame(PublicFileWriter::STATUS_OFF, $result['status']);
        $this->assertCount(count(self::ALL) - 1, $result['removed']);
        $this->assertSame(['favicon.ico'], $this->listing());
        $this->assertSame('edited by hand', $this->file('favicon.ico'));
    }

    public function testOnlyRootLevelAliasesAreWritten()
    {
        Config::modify()->set(FaviconService::class, 'extra_paths', [
            'sites/favicon.ico' => 'favicon.ico',
            'shortcut.ico' => 'favicon.ico',
        ]);
        $result = $this->build();
        $this->assertContains('shortcut.ico', $result['written']);
        $this->assertSame($this->served('favicon.ico'), $this->file('shortcut.ico'));
        $this->assertFileDoesNotExist($this->webroot . '/sites', 'no folder that could shadow a page URL');
        $this->assertSame('favicon.ico', $this->service()->resolveRequestPath('sites/favicon.ico'), 'still answered by the route');
    }

    public function testASettingsSaveUpdatesTheManifest()
    {
        $this->build();
        $config = SiteConfig::current_site_config();
        $config->Title = 'Renamed Site';
        $config->write();
        $manifest = json_decode($this->file('site.webmanifest'), true);
        $this->assertSame('Renamed Site', $manifest['name']);
        $this->assertSame($this->file('site.webmanifest'), $this->file('manifest.json'));
    }
}

<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Matcher;

use Diffalyzer\Matcher\FullScanMatcher;
use PHPUnit\Framework\TestCase;

final class FullScanMatcherTest extends TestCase
{
    private FullScanMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FullScanMatcher();
    }

    public function testComposerJsonTriggersFullScan(): void
    {
        $changedFiles = ['composer.json', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertTrue($result);
    }

    public function testComposerLockTriggersFullScan(): void
    {
        $changedFiles = ['composer.lock', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertTrue($result);
    }

    public function testComposerJsonInSubdirectoryTriggersFullScan(): void
    {
        $changedFiles = ['config/composer.json', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertTrue($result);
    }

    public function testNormalFilesDoNotTriggerFullScan(): void
    {
        $changedFiles = ['src/User.php', 'src/UserCollector.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertFalse($result);
    }

    public function testEmptyFilesDoNotTriggerFullScan(): void
    {
        $changedFiles = [];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertFalse($result);
    }

    public function testUserPatternTriggersFullScan(): void
    {
        $changedFiles = ['config/app.yml', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/.*\.yml$/');

        $this->assertTrue($result);
    }

    public function testUserPatternCombinedWithBuiltIn(): void
    {
        $changedFiles = ['src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/.*\.yml$/');

        $this->assertFalse($result);
    }

    public function testMultipleUserPatternsViaRegexAlternation(): void
    {
        $changedFiles = ['config/routes.xml'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/(.*\.yml|.*\.xml)$/');

        $this->assertTrue($result);
    }

    public function testNullUserPattern(): void
    {
        $changedFiles = ['src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertFalse($result);
    }

    public function testEmptyStringUserPattern(): void
    {
        $changedFiles = ['src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '');

        $this->assertFalse($result);
    }

    public function testConfigDirectoryPattern(): void
    {
        $changedFiles = ['config/services.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/^config\//');

        $this->assertTrue($result);
    }

    public function testPartialComposerFilenameDoesNotMatch(): void
    {
        $changedFiles = ['my-composer.json.backup', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, null);

        $this->assertFalse($result);
    }

    public function testGlobPatternViaCliParameter(): void
    {
        $changedFiles = ['phpunit.xml', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '*.xml');

        $this->assertTrue($result);
    }

    public function testGlobPatternDoesNotMatchWrongExtension(): void
    {
        $changedFiles = ['phpunit.xml', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '*.yml');

        $this->assertFalse($result);
    }

    public function testRegexPatternMatchesXmlFiles(): void
    {
        $changedFiles = ['phpunit.xml', 'config/test.xml', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/\.xml$/');

        $this->assertTrue($result);
    }

    public function testRegexPatternMatchesSpecificFilename(): void
    {
        $changedFiles = ['phpunit.xml.dist', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/phpunit\.xml/');

        $this->assertTrue($result);
    }

    public function testRegexPatternDoesNotMatchPartialString(): void
    {
        $changedFiles = ['phpunit.xml', 'src/User.php'];
        $result = $this->matcher->shouldTriggerFullScan($changedFiles, '/test\.xml/');

        $this->assertFalse($result);
    }

    public function testCliPatternOverridesConfigPatterns(): void
    {
        // Matcher with config patterns for composer files
        $matcher = new FullScanMatcher(['composer.json', 'composer.lock']);

        // CLI pattern should override and only match .xml files
        $changedFiles = ['composer.json', 'phpunit.xml', 'src/User.php'];
        $result = $matcher->shouldTriggerFullScan($changedFiles, '/\.xml$/');

        // Should trigger because phpunit.xml matches, not because composer.json matches config
        $this->assertTrue($result);
        $this->assertEquals('phpunit.xml', $matcher->getLastMatch()['file']);
        $this->assertEquals('/\.xml$/', $matcher->getLastMatch()['pattern']);
    }

    public function testGetLastMatchReturnsNullWhenNoMatch(): void
    {
        $changedFiles = ['src/User.php'];
        $this->matcher->shouldTriggerFullScan($changedFiles, '/\.xml$/');

        $this->assertNull($this->matcher->getLastMatch());
    }

    public function testGetLastMatchReturnsCorrectFileAndPattern(): void
    {
        $changedFiles = ['config/app.xml', 'src/User.php'];
        $this->matcher->shouldTriggerFullScan($changedFiles, '/\.xml$/');

        $match = $this->matcher->getLastMatch();
        $this->assertEquals('config/app.xml', $match['file']);
        $this->assertEquals('/\.xml$/', $match['pattern']);
    }
}

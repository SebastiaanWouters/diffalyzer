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
}

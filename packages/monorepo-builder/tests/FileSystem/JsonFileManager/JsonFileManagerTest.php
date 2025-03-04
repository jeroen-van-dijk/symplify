<?php

declare(strict_types=1);

namespace Symplify\MonorepoBuilder\Tests\FileSystem\JsonFileManager;

use Symplify\ComposerJsonManipulator\FileSystem\JsonFileManager;
use Symplify\MonorepoBuilder\HttpKernel\MonorepoBuilderKernel;
use Symplify\PackageBuilder\Testing\AbstractKernelTestCase;
use Symplify\SmartFileSystem\SmartFileInfo;
use Symplify\SmartFileSystem\SmartFileSystem;

final class JsonFileManagerTest extends AbstractKernelTestCase
{
    /**
     * @var JsonFileManager
     */
    private $jsonFileManager;

    /**
     * @var SmartFileSystem
     */
    private $smartFileSystem;

    protected function setUp(): void
    {
        $this->bootKernelWithConfigs(MonorepoBuilderKernel::class, [
            __DIR__ . '/config/inlined_section_config.php',
        ]);

        $this->jsonFileManager = $this->getService(JsonFileManager::class);
        $this->smartFileSystem = $this->getService(SmartFileSystem::class);
    }

    protected function tearDown(): void
    {
        $this->smartFileSystem->remove(__DIR__ . '/Source/second.json');
        $this->smartFileSystem->remove(__DIR__ . '/Source/third.json');
    }

    public function testLoad(): void
    {
        $expectedJson = [
            'key' => 'value',
        ];

        $loadedJson = $this->jsonFileManager->loadFromFilePath(__DIR__ . '/Source/first.json');
        $this->assertSame($expectedJson, $loadedJson);

        $loadedJsonFromFileInfo = $this->jsonFileManager->loadFromFileInfo(
            new SmartFileInfo(__DIR__ . '/Source/first.json')
        );
        $this->assertSame($expectedJson, $loadedJsonFromFileInfo);
    }

    public function testEncodeArrayToString(): void
    {
        $jsonContent = $this->jsonFileManager->encodeJsonToFileContent([
            'another_key' => 'another_value',
        ]);
        $this->assertStringEqualsFile(__DIR__ . '/Source/expected-second.json', $jsonContent);
    }

    public function testSaveWithInlinedSections(): void
    {
        $fileContent = $this->jsonFileManager->encodeJsonToFileContent([
            'inline_section' => [1, 2, 3],
            'normal_section' => [1, 2, 3],
        ]);

        $this->assertStringEqualsFile(__DIR__ . '/Source/expected-inlined.json', $fileContent);
    }
}

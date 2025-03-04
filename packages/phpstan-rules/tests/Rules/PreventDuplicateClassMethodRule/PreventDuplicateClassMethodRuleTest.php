<?php

declare(strict_types=1);

namespace Symplify\PHPStanRules\Tests\Rules\PreventDuplicateClassMethodRule;

use Iterator;
use PHPStan\Rules\Rule;
use Symplify\PHPStanExtensions\Testing\AbstractServiceAwareRuleTestCase;
use Symplify\PHPStanRules\Rules\PreventDuplicateClassMethodRule;
use Symplify\PHPStanRules\Tests\Rules\PreventDuplicateClassMethodRule\Fixture\DifferentMethodName1;
use Symplify\PHPStanRules\Tests\Rules\PreventDuplicateClassMethodRule\Fixture\WithNoParameter1;
use Symplify\PHPStanRules\Tests\Rules\PreventDuplicateClassMethodRule\Fixture\WithParameter1;

final class PreventDuplicateClassMethodRuleTest extends AbstractServiceAwareRuleTestCase
{
    /**
     * @param string[] $filePaths
     * @dataProvider provideData()
     */
    public function testRule(array $filePaths, array $expectedErrorMessagesWithLines): void
    {
        $this->analyse($filePaths, $expectedErrorMessagesWithLines);
    }

    public function provideData(): Iterator
    {
        yield [[__DIR__ . '/Fixture/ValueObject/SkipChair.php', __DIR__ . '/Fixture/ValueObject/SkipTable.php'], []];
        yield [[__DIR__ . '/Fixture/Entity/SkipApple.php', __DIR__ . '/Fixture/Entity/SkipCar.php'], []];

        yield [[__DIR__ . '/Fixture/SkipInterface.php'], []];
        yield [[__DIR__ . '/Fixture/SkipConstruct.php'], []];
        yield [[__DIR__ . '/Fixture/SkipTest.php'], []];
        yield [[__DIR__ . '/Fixture/SkipNodeType.php'], []];
        yield [[__DIR__ . '/Fixture/SkipSingleStmt.php'], []];

        yield [[
            __DIR__ . '/Fixture/SkipClassWithTrait.php',
            __DIR__ . '/Fixture/SkipTraitUsingTrait.php',
            __DIR__ . '/Fixture/SkipSomeTrait.php',
        ], []];

        $errorMessage = sprintf(
            PreventDuplicateClassMethodRule::ERROR_MESSAGE,
            'diff',
            'diff',
            WithNoParameter1::class
        );
        $errorMessage2 = sprintf(PreventDuplicateClassMethodRule::ERROR_MESSAGE, 'diff', 'diff', WithParameter1::class);
        yield [[
            __DIR__ . '/Fixture/WithNoParameter1.php',
            __DIR__ . '/Fixture/WithNoParameter2.php',
            __DIR__ . '/Fixture/WithParameter1.php',
            __DIR__ . '/Fixture/WithParameter2.php',
        ], [[$errorMessage, 9], [$errorMessage2, 9]]];

        $errorMessage = sprintf(
            PreventDuplicateClassMethodRule::ERROR_MESSAGE,
            'sleep',
            'go',
            DifferentMethodName1::class
        );
        yield [[
            __DIR__ . '/Fixture/DifferentMethodName1.php',
            __DIR__ . '/Fixture/DifferentMethodName2.php',
        ], [[$errorMessage, 9]]];
    }

    protected function getRule(): Rule
    {
        return $this->getRuleFromConfig(
            PreventDuplicateClassMethodRule::class,
            __DIR__ . '/../../../config/symplify-rules.neon'
        );
    }
}

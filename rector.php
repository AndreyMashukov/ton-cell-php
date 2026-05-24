<?php

declare(strict_types=1);

use Amashukov\RectorRules\NoArrayAssertContainsInTestsRector;
use Amashukov\RectorRules\NoAssertCallInSrcRector;
use Amashukov\RectorRules\NoAssertInsideIfInFunctionalTestsRector;
use Amashukov\RectorRules\NoCommentsOutsideInterfaceMethodDocBlockRector;
use Amashukov\RectorRules\NoDirectDbMutationInFunctionalTestsRector;
use Amashukov\RectorRules\NoDirectDispatchInFunctionalTestsRector;
use Amashukov\RectorRules\NoEnvironmentCheckInSrcRector;
use Amashukov\RectorRules\NoExistenceOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\NoPhpstanIgnoreRector;
use Amashukov\RectorRules\NoSuperglobalAccessRector;
use Amashukov\RectorRules\NoTypeOnlyAssertionsInTestsRector;
use Amashukov\RectorRules\RequirePsrClockInterfaceRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        codeQuality: true,
        deadCode: true,
        typeDeclarations: true,
    )
    ->withRules([
        NoCommentsOutsideInterfaceMethodDocBlockRector::class,
        NoPhpstanIgnoreRector::class,
        NoSuperglobalAccessRector::class,
        NoEnvironmentCheckInSrcRector::class,
        NoAssertCallInSrcRector::class,
        NoAssertInsideIfInFunctionalTestsRector::class,
        NoArrayAssertContainsInTestsRector::class,
        NoTypeOnlyAssertionsInTestsRector::class,
        NoExistenceOnlyAssertionsInTestsRector::class,
        RequirePsrClockInterfaceRector::class,
        NoDirectDbMutationInFunctionalTestsRector::class,
        NoDirectDispatchInFunctionalTestsRector::class,
    ])
    ->withImportNames();

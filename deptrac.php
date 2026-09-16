<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\ClassNameRegexConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('./src')
        ->layers(
            $domain = Layer::withName('Domain')->collectors(
                DirectoryConfig::create('Domain/(?!Exception/).*'),
            ),
            $domainException = Layer::withName('DomainException')->collectors(
                DirectoryConfig::create('Domain/Exception/.*'),
            ),
            $application = Layer::withName('Application')->collectors(
                DirectoryConfig::create('Application/.*'),
            ),
            $infrastructure = Layer::withName('Infrastructure')->collectors(
                DirectoryConfig::create('Infrastructure/.*'),
            ),
            $presentation = Layer::withName('Presentation')->collectors(
                DirectoryConfig::create('Presentation/.*'),
            ),
            $allowed = Layer::withName('AcceptedFrameworkTypes')->collectors(
                ClassNameRegexConfig::create('#^Symfony\\Component\\Uid\\Uuid$#'),
                ClassNameRegexConfig::create('#^Symfony\\Component\\[^\\]+\\Attribute\\.*$#'),
            ),
            $symfony = Layer::withName('Symfony')->collectors(
                ClassNameRegexConfig::create(
                    '#^Symfony\\(?!Component\\Uid\\Uuid$|Component\\[^\\]+\\Attribute\\.*$).*#',
                ),
            ),
            $doctrine = Layer::withName('Doctrine')->collectors(
                ClassNameRegexConfig::create('#^Doctrine\\.*#'),
            ),
        )
        ->rulesets(
            Ruleset::forLayer($domain)->accesses($domainException, $allowed),
            Ruleset::forLayer($domainException)->accesses($domain, $allowed),
            Ruleset::forLayer($application)->accesses($domain, $domainException, $allowed),
            Ruleset::forLayer($infrastructure)->accesses($application, $domain, $domainException, $symfony, $doctrine, $allowed),
            Ruleset::forLayer($presentation)->accesses($application, $domain, $domainException, $symfony, $doctrine, $allowed),
        )
    ;
};

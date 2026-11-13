<?php

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Route names are already prefixed 'git_' in the controller attributes.
// Import them once on Symfony < 7.3 (7.3 registers them by itself):
//
//     # config/routes/git.yaml
//     git:
//         resource: '@GitBundle/Resources/config/routes.php'
//
// No import prefix: the controller's class-level #[Route] already applies
// %git.route_prefix%.
return static function (RoutingConfigurator $routes): void {
    $routes->import('../../src/Controller/', 'attribute');
};

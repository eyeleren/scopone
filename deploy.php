<?php

namespace Deployer;

require 'recipe/common.php';


set('application', 'scopone');
set('repository', 'https://github.com/eyeleren/scopone.git');
set('branch', 'main');


set('shared_files', []);
set('shared_dirs', []);
set('writable_dirs', []);


set('keep_releases', 3);

host('EC2')
    ->setHostname('87.0.230.67')
    ->setRemoteUser('jakala')
    ->setDeployPath('/var/www/scopone');

// Custom deploy task (minimal)
task('deploy', [
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:symlink',
    'cleanup',
    'deploy:unlock',
]);

after('deploy', 'success');

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

$host = getenv('SCOPONE_DEPLOY_HOST') ?: '';

host('EC2', $host)
    ->user('jakala')
    ->set('deploy_path', '/var/www/scopone')
    ->set('ssh_multiplexing', true)
    ->set('allow_sudo', true);

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

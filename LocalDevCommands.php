<?php

namespace Drush\Commands;

use Drupal\Core\Site\Settings;
use Drush\Drush;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Symfony\Component\Console\Input\ArrayInput;

/**
 *
 */
class LocalDevCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use SiteAliasManagerAwareTrait;

    /**
     * Enable modules that are excluded from config export.
     *
     * @bootstrap full
     *
     * @command backend:enable-dev-modules
     */
    public function enableDevModules()
    {
        $modules = Settings::get('config_exclude_modules', []);

        if (!count($modules)) {
            $this->logger()->warning('No modules defined in $settings[\'config_exclude_modules\'].');

            return;
        }

        $process = $this->processManager()->drush($this->siteAliasManager()->getSelf(), 'pm:enable', $modules, Drush::redispatchOptions());
        $process->mustRun($process->showRealtime());
    }
}

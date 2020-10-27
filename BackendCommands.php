<?php

namespace Drush\Commands\BurdaStyleGroup;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\Sql\SqlBase;
use Symfony\Component\Filesystem\Filesystem;

include "BackendCommandsTrait.php";

/**
 * Backend drush commands.
 */
class BackendCommands extends DrushCommands implements SiteAliasManagerAwareInterface
{
    use BackendCommandsTrait;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $filesystem;

    /**
     * BackendCommands constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->filesystem = new Filesystem();
    }

    /**
     * Prepare file system and code to be ready for install.
     *
     * @hook pre-command backend:install
     *
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function preCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();

        if ($this->environment !== 'local') {
            // Remove local config to prevent pollution of export with development values caused by nimbus.
            // @todo Remove after nimbus is gone.
            $this->filesystem->remove($this->siteConfigSyncDirectory().'/../local');
        }

        // Apply core patches
        $this->corePatches();
    }

    /**
     * Install a BurdaStyle backend site from existing configuration.
     *
     * @command backend:install
     *
     * @aliases backend:si
     *
     * @validate-site-alias
     *
     * @options-backend
     *
     * @usage drush @elle backend:install-site
     *   Installs elle project from config.
     *
     * @bootstrap config
     *
     * @kernel installer
     */
    public function install()
    {
        // Cleanup existing installation.
        $this->drush($this->selfRecord(), 'sql-create', [], ['yes' => true]);
        $this->drush($this->selfRecord(), 'cache:rebuild');

        // Do the site install
        $this->drush($this->selfRecord(), 'site:install', [], ['existing-config' => true, 'yes' => true]);
    }

    /**
     * Clean-up installation side-effects.
     *
     * @hook post-command backend:install
     *
     * @param $result
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function postInstallCommand($result, CommandData $commandData)
    {
        // Remove the patch.
        $this->corePatches($revert = true);

        // Cleanup config sync directory we filled up before and revert changes made by site-install
        $this->process(['git', 'clean', '--force', '--quiet', '.'], $this->siteConfigSyncDirectory());
        $this->process(['git', 'checkout', '.'], $this->siteConfigSyncDirectory());

        $this->process(['git', 'checkout', $this->siteDirectory().'/settings.php'], $this->projectDirectory());

        if ($this->environment !== 'local') {
            $this->process(['git', 'checkout', $this->siteConfigSyncDirectory().'/../local']);
        }
    }

    /**
     * Update the BurdaStyle backend code including translations.
     *
     * @command backend:update-code
     *
     * @options-backend
     *
     * @usage drush backend:update-code
     *   Update code and translation files.
     */
    public function updateCode()
    {
        $this->process(['composer', 'update'], $this->projectDirectory());
        $this->process(['scripts/update-po.sh'], $this->projectDirectory());
    }

    /**
     * Update a BurdaStyle backend database for a specific site.
     *
     * @command backend:update-database
     *
     * @aliases backend:updb
     *
     * @options-backend
     *
     * @usage drush @elle backend:update-databases
     *   Update the database for elle.
     */
    public function updateDatabase()
    {
        $this->drush($this->selfRecord(), 'updatedb', [], ['yes' => true]);
        $this->drush($this->selfRecord(), 'cache:rebuild');
        $this->drush($this->selfRecord(), 'locale-update', [], ['yes' => true]);
    }

    /**
     * Export configuration of BurdaStyle backend site.
     *
     * @command backend:config-export
     *
     * @aliases backend:cex
     *
     * @options-backend
     *
     * @validate-site-alias
     *
     * @usage drush @elle backend:export-config
     *   Exports the elle configuration.
     *
     * @bootstrap config
     */
    public function configExport()
    {
        // export the config into the export folder.
        $this->drush($this->selfRecord(), 'config:export', [], ['yes' => true]);

        if ($this->environment !== 'local') {
            // Nimbus will overwrite local config files with the production values.
            $this->process(['git', 'checkout', $this->siteConfigSyncDirectory().'/../local']);
        }

        // Move config into shared and site specific folders.
        // @todo Fix sync-config.sh expecting relative path.
        $this->process(
            ['scripts/sync-config.sh', $this->siteConfigSyncDirectory().'/../export'],
            $this->projectDirectory()
        );
    }

    /**
     * Prepare an update branch. Does code update, database update and config export.
     *
     * @command backend:prepare-update-branch
     *
     * @options-backend
     *
     * @usage drush backend:prepare-update-branch
     *   Updates code and all configurations for all sites.
     * @usage drush @elle backend:prepare-update-branch
     *   Updates code and configurations for the site elle only.
     */
    public function prepareUpdateBranch()
    {
        if ($this->selfRecord()->name() === '@self') {
            $aliases = $this->supportedAliases();
        } else {
            $aliases = [$this->selfRecord()->name()];
        }

        // First install all sites with current code base.
        $this->process(['composer', 'install'], $this->projectDirectory());

        // We use ::process() instead of ::drush() in the following drush calls
        // to be able to provide the @alias. with ::drush, this would be translated
        // into --uri=http://domain.site, which we do not handle in code.
        foreach ($aliases as $alias) {
            // Install from config.
            $this->process(['drush', $alias, 'backend:install'] + $this->getOptions(), $this->projectDirectory());
        }

        // Update codebase and translation files
        $this->process(['drush', 'backend:update-code'] + $this->getOptions(), $this->projectDirectory());

        // Update database and export config for all sites.
        foreach ($aliases as $alias) {
            $this->process(['drush', $alias, 'backend:update-database'] + $this->getOptions(), $this->projectDirectory());
            $this->process(['drush', $alias, 'backend:config-export'] + $this->getOptions(), $this->projectDirectory());
        }
    }

    /**
     * Creates a phpunit database generation script.
     *
     * @command backend:create-testing-dump
     *
     * @aliases backend:dump
     *
     * @options-backend
     *
     * @usage drush @elle backend:create-testing-dump
     *   Creates a phpunit database generation script for the site elle.
     *
     * @bootstrap config
     */
    public function createTestingDump()
    {
        $sql = SqlBase::create();
        $dbSpec = $sql->getDbSpec();
        $dbUrl = $dbSpec['driver'].'://'.$dbSpec['username'].':'.$dbSpec['password'].'@'.$dbSpec['host'].':'.$dbSpec['port'].'/'.$dbSpec['database'];

        $this->process(['php', 'core/scripts/db-tools.php', 'dump-database-d8-mysql', '--database-url', $dbUrl], $this->drupalRootDirectory());
    }

    /**
     * Gets an options from the input options.
     *
     * @return string[]
     */
    protected function getOptions()
    {
        $options = [];
        foreach ($this->input()->getOptions() as $key => $value) {
            if (!empty($value) && 'root' !== $key) {
                $options[] = '--'.$key.'='.$value.' ';
            }
        }

        return $options;
    }

    /**
     * Prepare the config/{site}/sync for being used by site-install.
     *
     * This is necessary, because config files are distributed to different
     * folders by nimbus.
     */
    protected function populateConfigSyncDirectory()
    {
        // Prepare config-sync directory for site install with existing config.
        // First copy shared config into config/{site}/sync, then overwrite this
        // with files from config/{site}/override.
        $this->filesystem->mirror(
            $this->siteConfigSyncDirectory().'/../../shared',
            $this->siteConfigSyncDirectory(),
            null,
            ['override' => true]
        );
        $this->filesystem->mirror(
            $this->siteConfigSyncDirectory().'/../override',
            $this->siteConfigSyncDirectory(),
            null,
            ['override' => true]
        );

        if ($this->environment === 'local') {
            $this->filesystem->mirror(
                $this->siteConfigSyncDirectory().'/../local',
                $this->siteConfigSyncDirectory(),
                null,
                ['override' => true]
            );
        } elseif ($this->environment === 'testing') {
            $this->filesystem->mirror(
                $this->siteConfigSyncDirectory().'/../testing',
                $this->siteConfigSyncDirectory(),
                null,
                ['override' => true]
            );
        }
    }

    /**
     * Apply or revoke patches to drupal core.
     *
     * @param bool $revert
     */
    protected function corePatches(bool $revert = false)
    {
        $patches = [
          'https://www.drupal.org/files/issues/2020-09-14/3169756-2-11.patch',
          'https://www.drupal.org/files/issues/2020-06-03/2488350-3-98.patch',
          'https://www.drupal.org/files/issues/2020-07-17/3086307-48.patch',
        ];

        $command = ['patch', '-p1'];
        if ($revert) {
            $command[] = '-R';
            $patches = array_reverse($patches);
        }

        foreach ($patches as $patch) {
            $stream = fopen($patch, 'r');
            $this->process($command, $this->drupalRootDirectory(), null, $stream);
            fclose($stream);
        }
    }
}

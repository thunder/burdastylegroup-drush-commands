<?php

namespace Drush\Commands\BurdaStyleGroup;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\Sql\SqlBase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

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
    public function preInstallCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();

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
        $this->drush($this->selfRecord(), 'sql-create', [], ['yes' => $this->input()->getOption('yes')]);
        $this->drush($this->selfRecord(), 'cache:rebuild');

        // Do the site install
        $this->drush($this->selfRecord(), 'site:install', [], ['existing-config' => true, 'yes' => $this->input()->getOption('yes')]);
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
        $this->process(['git', 'checkout', $this->siteDirectory().'/settings.php'], $this->projectDirectory());
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
        $this->drush($this->selfRecord(), 'updatedb', [], ['yes' => $this->input()->getOption('yes')]);
        $this->drush($this->selfRecord(), 'cache:rebuild');
        $this->drush($this->selfRecord(), 'locale-update', [], ['yes' => $this->input()->getOption('yes')]);
    }

    /**
     * Check, if prod environment is exported.
     *
     * TODO: Well not be needed when there are no environment specific config folders.
     *
     * @hook validate backend:config-export
     *
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     *
     * @throws \Exception
     */
    public function validateConfigExport(CommandData $commandData)
    {
        if ($this->environment !== 'prod') {
            throw new \Exception(dt('Only production can be exported. Current environment is "%environment".', ['%environment' => $this->environment]));
        }
    }

    /**
     * Runs populateConfigSyncDirectory() for backend:config-export.
     *
     * As long as we have to handle environment specific config, this can not
     * be a post command for the default drush config:import command.
     *
     * TODO: Revisit when we do not need the local- and testing-environment config
     * TODO: folder anymore. Then decide if we can make this a post command for config.
     *
     * @hook pre-command backend:config-export
     *
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function preConfigExportCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();
    }

    /**
     * Export configuration of BurdaStyle backend site.
     *
     * TODO: Revisit when we do not need the local- and testing-environment config
     * TODO: folder anymore. Then decide if we can make this a post command for config:export.
     *
     * @command backend:config-export
     *
     * @aliases backend:cex
     *
     * @options-backend
     *
     * @usage drush @elle backend:config-export
     *   Exports the elle configuration.
     *
     * @bootstrap config
     */
    public function configExport()
    {
        $this->drush($this->selfRecord(), 'config:export', [], ['yes' => $this->input()->getOption('yes')]);
    }

    /**
     * Move files from sync folder to shared or override folders.
     *
     * As long as we have to handle environment specific config, this can not
     * be a post command for the default drush config:import command.
     *
     * TODO: Revisit when we do not need the local- and testing-environment config
     * TODO: folder anymore. Then decide if we can make this a post command for config:export.
     *
     * @hook post-command backend:config-export
     *
     * @param $result
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function postConfigExportCommand($result, CommandData $commandData)
    {
        $exportedFiles = $this->getConfigFilesInDirectory($this->siteConfigSyncDirectory());
        $overrideFiles = $this->getConfigFilesInDirectory($this->siteConfigOverrideDirectory());
        $sharedFiles = $this->getConfigFilesInDirectory($this->configSharedDirectory());
        $localFiles = $this->getConfigFilesInDirectory($this->siteConfigEnvironmentDirectory('local'));
        $modifiedFiles = [];

        foreach ($exportedFiles as $fileName => $fullPath) {
            // First check, if the file should be put into the override directory.
            // otherwise check, if the file should be put into the shared directory.
            if (isset($overrideFiles[$fileName])) {
                if (!$this->filesAreEqual($overrideFiles[$fileName], $fullPath)) {
                    $this->filesystem->copy($fullPath, $overrideFiles[$fileName], true);
                    $modifiedFiles[$fileName] = $fullPath;
                }
            } elseif (isset($sharedFiles[$fileName])) {
                if (!$this->filesAreEqual($sharedFiles[$fileName], $fullPath)) {
                    $this->filesystem->copy($fullPath, $sharedFiles[$fileName], true);
                    $modifiedFiles[$fileName] = $fullPath;
                }
            }
        }

        // Give information to the user, when we modified a configuration that also exists in local config.
        // TODO: revisit, when local config folder has been removed.
        foreach ($modifiedFiles as $fileName => $fullPath) {
            if (isset($localFiles[$fileName])) {
                $this->io()->block('Configuration file "'.$fileName.'" was changed and exists in local config folder. Please check, if local config has to be manually modified.', 'INFO', 'fg=yellow');
            }
        }

        // Remove files from override, that were not exported anymore.
        foreach ($overrideFiles as $fileName => $fullPath) {
            if (!isset($exportedFiles[$fileName])) {
                $this->filesystem->remove($fullPath);
            }
        }

        // Remove files from shared, that were not exported anymore.
        foreach ($sharedFiles as $fileName => $fullPath) {
            if (!isset($exportedFiles[$fileName])) {
                $this->filesystem->remove($fullPath);
            }
        }
    }

    /**
     * Runs populateConfigSyncDirectory() for backend:config-import.
     *
     * As long as we have to handle environment specific config, this can not
     * be a post command for the default drush config:import command.
     *
     * TODO: Revisit when we do not need the local- and testing-environment config
     * TODO: folder anymore. Then decide if we can make this a post command for config:*.
     *
     * @hook pre-command backend:config-import
     *
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function preConfigImportCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();
    }

    /**
     * Import configuration of BurdaStyle backend site.
     *
     * This is simple wrapper to default the config:import command. it is only
     * until we can get rid of the environment specific config directories
     * (local and testing).
     *
     * TODO: Revisit when we do not need local and testing anymore. Then we can
     * TODO: delete this command and change the preConfigImportCommand to hook
     * TODO: to the default config:import command.
     *
     * @command backend:config-import
     *
     * @aliases backend:cim
     *
     * @options-backend
     *
     * @usage drush @elle backend:config-import
     *   Exports the elle configuration.
     *
     * @bootstrap config
     */
    public function configImport()
    {
        $this->drush($this->selfRecord(), 'config:import', [], ['yes' => $this->input()->getOption('yes')]);
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
     * Gets a cleaned up array of $key=$value strings from the input options.
     *
     * @return string[]
     */
    protected function getOptions()
    {
        $options = [];
        foreach ($this->input()->getOptions() as $key => $value) {
            if (!empty($value) && 'root' !== $key) {
                $options[] = '--'.$key.'='.$value;
            }
        }

        return $options;
    }

    /**
     * Prepare the config/{site}/sync for being used by site-install, config-export and config-import.
     *
     * This is necessary, because config files are distributed to different
     * folders.
     */
    protected function populateConfigSyncDirectory(): void
    {
        // Prepare config-sync directory for site install with existing config.
        // First copy shared config into config/{site}/sync, then overwrite this
        // with files from config/{site}/override.
        $this->filesystem->mirror(
            $this->configSharedDirectory(),
            $this->siteConfigSyncDirectory(),
            null,
            ['override' => true]
        );
        $this->filesystem->mirror(
            $this->siteConfigOverrideDirectory(),
            $this->siteConfigSyncDirectory(),
            null,
            ['override' => true]
        );
        if ($this->filesystem->exists($this->siteConfigEnvironmentDirectory($this->environment))) {
            $this->filesystem->mirror(
                $this->siteConfigEnvironmentDirectory($this->environment),
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

    /**
     * Get all config files in a given directory.
     *
     * @param $directory
     *   The directory to find config files in.
     * @return string[]
     *   The filenames of found config files.
     */
    private function getConfigFilesInDirectory($directory)
    {
        if ($this->filesystem->exists($directory) === false) {
            return [];
        }

        $configFiles = [];

        // Finder does not reset its internal state, we need a new instance
        // everytime we use it.
        $finder = new Finder();

        foreach ($finder->files()->name('*.yml')->in($directory) as $file) {
            $configFiles[$file->getFilename()] = $file->getPath().DIRECTORY_SEPARATOR.$file->getFilename();
        }

        return $configFiles;
    }

    /**
     * Check if two file have the same content.
     *
     * @param $firstFile
     * @param $secondFile
     *
     * @return bool
     */
    private function filesAreEqual($firstFile, $secondFile): bool
    {
        if (filesize($firstFile) !== filesize($secondFile)) {
            return false;
        }

        $firstFileHandler = fopen($firstFile, 'rb');
        $secondFileHandler = fopen($secondFile, 'rb');

        while (!feof($firstFileHandler)) {
            if (fread($firstFileHandler, 1024) != fread($secondFileHandler, 1024)) {
                return false;
            }
        }

        fclose($firstFileHandler);
        fclose($secondFileHandler);

        return true;
    }
}

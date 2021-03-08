<?php

namespace Drush\Commands\BurdaStyleGroup;

use Consolidation\AnnotatedCommand\AnnotationData;
use Drupal\Core\Site\Settings;
use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\SiteAlias\SiteAliasManagerAwareInterface;
use Drush\Sql\SqlBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
     * Add option to command.
     *
     * @hook option config:export
     *
     * @param \Symfony\Component\Console\Command\Command     $command
     * @param \Consolidation\AnnotatedCommand\AnnotationData $annotationData
     */
    public function additionalConfigExportOption(Command $command, AnnotationData $annotationData)
    {
        $command->addOption(
            'project-directory',
            '',
            InputOption::VALUE_NONE,
            'The base directory of the project. Defaults to composer root of project. Option added by burdastyle backend commands.'
        );
    }

    /**
     * @hook init config:export
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Consolidation\AnnotatedCommand\AnnotationData  $annotationData
     */
    public function initConfigExportCommand(InputInterface $input, AnnotationData $annotationData)
    {
        $this->initCommands($input, $annotationData);
    }

    /**
       * Runs populateConfigSyncDirectory() for backend:config-export.
       *
       * @hook pre-command config:export
       *
       * @param \Consolidation\AnnotatedCommand\CommandData $commandData
       */
    public function preConfigExportCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();
    }

    /**
     * Move files from sync folder to shared or override folders.
     *
     * @hook post-command config:export
     *
     * @param $result
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function postConfigExportCommand($result, CommandData $commandData)
    {
        $exportedFiles = $this->getConfigFilesInDirectory($this->siteConfigSyncDirectory());
        $overrideFiles = $this->getConfigFilesInDirectory($this->siteConfigOverrideDirectory());
        $sharedFiles = $this->getConfigFilesInDirectory($this->configSharedDirectory());
        $modifiedFiles = [];

        foreach ($exportedFiles as $fileName => $fullPath) {
            // First check, if the file should be put into the override directory.
            // otherwise check, if the file should be put into the shared directory.
            // Finally, if file is new (neither in shared nor in override),
            // put it into override and inform user of new config.
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
            } else {
                $this->filesystem->copy($fullPath, $this->siteConfigOverrideDirectory().'/'.$fileName);
                $this->io()->block('New configuration file "'.$fileName.'" was added to override folder. Please check, if that is the correct location.', 'INFO', 'fg=yellow');
                $modifiedFiles[$fileName] = $fullPath;
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
                $this->io()->block('Configuration file "'.$fileName.'" was removed from the shared folder. Please check, if overridden config in other sub-sites has to be manually modified or deleted.', 'INFO', 'fg=yellow');
            }
        }
        if (count($modifiedFiles)) {
            $this->io()->block('Check all config files if they have been moved to the correct location!', 'INFO', 'fg=yellow');
        }
    }

    /**
     * Runs populateConfigSyncDirectory() for config:import.
     *
     * @hook pre-command config:import
     *
     * @param \Consolidation\AnnotatedCommand\CommandData $commandData
     */
    public function preConfigImportCommand(CommandData $commandData)
    {
        $this->populateConfigSyncDirectory();
    }

    /**
     * Add option to command.
     *
     * @hook option config:import
     *
     * @param \Symfony\Component\Console\Command\Command     $command
     * @param \Consolidation\AnnotatedCommand\AnnotationData $annotationData
     */
    public function additionalConfigImportOption(Command $command, AnnotationData $annotationData)
    {
        $command->addOption(
            'project-directory',
            '',
            InputOption::VALUE_NONE,
            'The base directory of the project. Defaults to composer root of project. Option added by burdastyle backend commands.'
        );
    }

    /**
     * @hook init config:import
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Consolidation\AnnotatedCommand\AnnotationData  $annotationData
     */
    public function initConfigImportCommand(InputInterface $input, AnnotationData $annotationData)
    {
        $this->initCommands($input, $annotationData);
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
     * Enable modules that are excluded from config export.
     *
     * @command backend:enable-dev-modules
     *
     * @options-backend
     *
     * @bootstrap full
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
     * This is necessary, because config files are distributed to different folders.
     */
    protected function populateConfigSyncDirectory(): void
    {
        $syncDirectory = $this->siteConfigSyncDirectory();
        $syncFiles = $this->getConfigFilesInDirectory($syncDirectory);
        $sharedFiles = $this->getConfigFilesInDirectory($this->configSharedDirectory());
        $overrideFiles = $this->getConfigFilesInDirectory($this->siteConfigOverrideDirectory());

        // Prepare config-sync directory for site install with existing config.
        // First clean up the directory and copy shared config into
        // config/{site}/sync, then overwrite this with files from config/{site}/override.
        $this->filesystem->remove($syncFiles);
        foreach ($sharedFiles as $fileName => $fullPath) {
            $this->filesystem->copy($fullPath, $syncDirectory.'/'.$fileName, true);
        }
        foreach ($overrideFiles as $fileName => $fullPath) {
            $this->filesystem->copy($fullPath, $syncDirectory.'/'.$fileName, true);
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

        $command = ['patch', '-p1', '--silent'];
        if ($revert) {
            $command[] = '-R';
            $patches = array_reverse($patches);
        }

        foreach ($patches as $patch) {
            $stream = fopen($patch, 'r');
            try {
                $this->process($command, $this->drupalRootDirectory(), null, $stream);
            } catch (\Exception $e) {
                $this->logger()->info('A patch was not applied correctly, continuing without this patch.');
            }
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

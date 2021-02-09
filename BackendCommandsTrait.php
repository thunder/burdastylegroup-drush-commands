<?php

namespace Drush\Commands\BurdaStyleGroup;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drupal\Core\Site\Settings;
use Drush\Drush;
use Symfony\Component\Console\Input\InputInterface;
use Webmozart\PathUtil\Path;

/**
 * Trait for backend drush commands.
 */
trait BackendCommandsTrait
{
    use SiteAliasManagerAwareTrait;

    /**
     * Maps site alias to site directory.
     *
     * @var string[]
     */
    private $siteDomainDirectoryMapping = [
        '@elle.dev' => 'elle.de',
        '@esquire.dev' => 'esquire.de',
        '@freundin.dev' => 'freundin.de',
        '@harpersbazaar.dev' => 'harpersbazaar.de',
        '@instyle.dev' => 'instyle.de',
    ];

    /**
     * @var string
     */
    private $projectDirectory;

    /**
     * @var string
     */
    private $environment;

    /**
     * @hook init
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Consolidation\AnnotatedCommand\AnnotationData  $annotationData
     */
    public function initCommands(InputInterface $input, AnnotationData $annotationData)
    {
        // Initialize project directory.
        $this->projectDirectory = $input->getOption('project-directory') ?: Drush::bootstrapManager()->getComposerRoot();
        $this->environment = $input->getOption('environment');
    }

    /**
     * Define default options for most backend commands.
     *
     * @hook option @options-backend
     *
     * @option project-directory The base directory of the project. Defaults to composer root of project .
     * @option environment Choose environment the installation is built for, i.e. which config folders are used.
     *   Possible values are "local", "testing" and "prod". (Defaults to "prod").
     *
     * @param array $options
     */
    public function optionsBackend($options = ['project-directory' => false, 'environment' => 'prod'])
    {
    }

    /**
     * Drush command wrapper.
     *
     * Runs the command and prints the output.
     *
     * @param \Consolidation\SiteAlias\SiteAliasInterface $siteAlias
     * @param $command
     * @param array                                       $args
     * @param array                                       $options
     * @param array                                       $optionsDoubleDash
     */
    protected function drush(SiteAliasInterface $siteAlias, $command, $args = [], $options = [], $optionsDoubleDash = [])
    {
        /**
         * @var \Consolidation\SiteProcess\SiteProcess $process
         */
        $process = $this->processManager()->drush($siteAlias, $command, $args, $options, $optionsDoubleDash);

        $isTtySupported = (bool) @proc_open('echo 1 >/dev/null', [['file', '/dev/tty', 'r'], ['file', '/dev/tty', 'w'], ['file', '/dev/tty', 'w']], $pipes);
        if ($isTtySupported) {
          $process->setTty(TRUE);
        }
        $process->mustRun($process->showRealtime());
    }

    /**
     * Process wrapper.
     *
     * Runs the process and prints the output.
     *
     * @param $command
     * @param null       $cwd
     * @param array|null $env
     * @param null       $input
     * @param int        $timeout
     */
    protected function process($command, $cwd = null, array $env = null, $input = null, $timeout = 60)
    {
        $process = $this->processManager()->process($command, $cwd, $env, $input, $timeout);
        $process->mustRun($process->showRealtime());
    }

    /**
     * The base project directory, where most commands will be executed from.
     *
     * @return \Consolidation\AnnotatedCommand\CommandError|string
     */
    protected function projectDirectory(): string
    {
        if (isset($this->projectDirectory)) {
            return $this->projectDirectory;
        }

        $msg = dt('The project directory has not been set.');

        return new CommandError($msg);
    }

    /**
     * The drupal root directory for a given site.
     *
     * @return string
     */
    protected function drupalRootDirectory(): string
    {
        return $this->projectDirectory().'/docroot';
    }

    /**
     * The site directory for a given site.
     * @see SiteInstallCommands::getSitesSubdirFromUri().
     *
     * @return string
     */
    protected function siteDirectory(): string
    {
        $uri = preg_replace('#^https?://#', '', $this->selfRecord()->get('uri'));
        $sitesFile = $this->drupalRootDirectory().'/sites/sites.php';
        if (file_exists($sitesFile)) {
            include $sitesFile;
            /** @var array $sites */
            if (isset($sites) && array_key_exists($uri, $sites)) {
                return Path::join($this->drupalRootDirectory(), 'sites', $sites[$uri]);
            }
        }
        // Fall back to default directory if it exists.
        if (file_exists(Path::join($this->drupalRootDirectory(), 'sites', 'default'))) {
            return 'default';
        }

        return false;
    }

    /**
     * The directory, where site specific configuration is placed.
     *
     * @return string
     */
    protected function siteConfigSyncDirectory(): string
    {
        return $this->drupalRootDirectory().'/'.Settings::get('config_sync_directory', false);
    }

    /**
     * The directory, where shared configuration is placed.
     *
     * @return string
     */
    protected function configSharedDirectory(): string
    {
        return $this->siteConfigSyncDirectory().'/../../shared';
    }

    /**
     * The directory, where site specific overridden configuration is placed.
     *
     * @return string
     */
    protected function siteConfigOverrideDirectory(): string
    {
        return $this->siteConfigSyncDirectory().'/../override';
    }

    /**
     * The directory, where site specific configuration for the current environment is placed.
     *
     * @param string $environment
     *  The environment to get the config directory for.
     * @return string
     */
    protected function siteConfigEnvironmentDirectory(string $environment): string
    {
        return $this->siteConfigSyncDirectory().'/../'.$environment;
    }

    /**
     * Get the '@self' alias record.
     *
     * @return \Consolidation\SiteAlias\SiteAlias|\Consolidation\SiteAlias\SiteAliasInterface
     */
    protected function selfRecord(): SiteAliasInterface
    {
        return $this->siteAliasManager()->getSelf();
    }

    /**
     * The supported site alias records.
     *
     * @return string[]
     */
    protected function supportedAliases()
    {
        return array_keys($this->siteDomainDirectoryMapping);
    }
}

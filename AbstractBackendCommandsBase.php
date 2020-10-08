<?php

namespace Drush\Commands\burdastylegroup_drush_commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Consolidation\SiteAlias\SiteAliasInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareInterface;
use Consolidation\SiteAlias\SiteAliasManagerAwareTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Base class for backend drush commands.
 */
abstract class AbstractBackendCommandsBase extends DrushCommands implements SiteAliasManagerAwareInterface
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
    private $siteDomainDirectory;

    /**
     * @var bool
     */
    private $forceProduction;

    /**
     * @hook init
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Consolidation\AnnotatedCommand\AnnotationData  $annotationData
     */
    public function initCommands(InputInterface $input, AnnotationData $annotationData)
    {
        // Initialize project directory.
        $this->projectDirectory = $input->getOption('project-directory');
        $this->forceProduction = (bool) $input->getOption('force-production');
    }

    /**
     * Validate, that the given site alias is supported by this drush command.
     *
     * @hook validate @validate-site-alias
     *
     * @param  \Consolidation\AnnotatedCommand\CommandData $commandData
     *
     * @return \Consolidation\AnnotatedCommand\CommandError|null
     */
    public function validateSite(CommandData $commandData)
    {
        $aliasName = $this->selfRecord()->name();

        if (!in_array($aliasName, $this->supportedAliases())) {
            $msg = dt('Site !name does not exist, or is not supported.', ['!name' => $aliasName]);

            return new CommandError($msg);
        }
    }

    /**
     * Define default options for most backend commands.
     *
     * @hook option @options-backend
     *
     * @option project-directory The base directory of the project. Defaults to '/var/www/html'.
     * @option force-production The installation is forced to be without the local config. Defaults to false.
     *
     * @param array $options
     */
    public function optionsBackend($options = ['project-directory' => '/var/www/html', 'force-production' => false])
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
        $process->setTty(true);
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
    protected function projectDirectory()
    {
        if (isset($this->projectDirectory)) {
            return $this->projectDirectory;
        }

        $msg = dt('The project directory has not been set.');

        return new CommandError($msg);
    }

    /**
     * The site directory for a given site.
     *
     * @return string
     */
    protected function siteDirectory(): string
    {
        return $this->projectDirectory().'/docroot/sites/'.$this->siteDomainDirectory();
    }

    /**
     * The directory, where the shared configuration is placed.
     *
     * @return string
     */
    protected function sharedConfigDirectory(): string
    {
        return $this->projectDirectory().'/config/shared';
    }

    /**
     * The directory, where site specific configuration is placed.
     *
     * @return string
     */
    protected function siteConfigDirectory(): string
    {
        return $this->projectDirectory().'/config/'.$this->siteDomainDirectory();
    }

    /**
     * @return bool
     */
    protected function forceProduction(): bool
    {
        return $this->forceProduction;
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

    /**
     * @return string
     */
    private function siteDomainDirectory(): string
    {
        if (isset($this->siteDomainDirectory)) {
            return $this->siteDomainDirectory;
        }

        $this->siteDomainDirectory = $this->siteDomainDirectoryMapping[$this->selfRecord()->name()];

        return $this->siteDomainDirectory;
    }
}

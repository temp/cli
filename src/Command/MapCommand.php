<?php

/*
 * This file is part of the puli/cli package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Cli\Command;

use Puli\RepositoryManager\ManagerFactory;
use Puli\RepositoryManager\Package\PackageManager;
use Puli\RepositoryManager\Repository\RepositoryManager;
use Puli\RepositoryManager\Repository\ResourceMapping;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Console\Command\Command;
use Webmozart\Console\Input\InputOption;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class MapCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('map')
            ->setDescription('Show and manipulate resource mappings.')
            ->addArgument('repository-path', InputArgument::OPTIONAL)
            ->addArgument('path', InputArgument::OPTIONAL | InputArgument::IS_ARRAY)
            ->addOption('root', null, InputOption::VALUE_NONE, 'Show mappings of the root package')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Show mappings of a package', null, 'package')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show mappings of all packages')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = ManagerFactory::createProjectEnvironment(getcwd());
        $packageManager = ManagerFactory::createPackageManager($environment);
        $repoManager = ManagerFactory::createRepositoryManager($environment, $packageManager);

        if ($input->getArgument('repository-path')) {
            return $this->mapResource(
                $input->getArgument('repository-path'),
                $input->getArgument('path'),
                $repoManager
            );
        }

        $packageNames = $this->getPackageNames($input, $packageManager);

        return $this->listResourceMappings($output, $repoManager, $packageNames);
    }

    /**
     * @param string            $repositoryPath
     * @param string[]          $filesystemPaths
     * @param RepositoryManager $repoManager
     *
     * @return int
     */
    private function mapResource($repositoryPath, array $filesystemPaths, RepositoryManager $repoManager)
    {
        $repoManager->addResourceMapping(new ResourceMapping(
            $repositoryPath,
            $filesystemPaths
        ));

        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param RepositoryManager $repoManager
     *
     * @return int
     */
    private function listResourceMappings(OutputInterface $output, RepositoryManager $repoManager, $packageNames = null)
    {
        if (1 === count($packageNames)) {
            $mappings = $repoManager->getResourceMappings(reset($packageNames));
            $this->printMappingTable($output, $mappings);

            return 0;
        }

        foreach ($packageNames as $packageName) {
            $mappings = $repoManager->getResourceMappings($packageName);

            if (!$mappings) {
                continue;
            }

            $output->writeln("<b>$packageName</b>");
            $this->printMappingTable($output, $mappings);
            $output->writeln('');
        }

        return 0;
    }

    /**
     * @param InputInterface $input
     * @param PackageManager $packageManager
     *
     * @return string[]|null
     */
    private function getPackageNames(InputInterface $input, PackageManager $packageManager)
    {
        // Display all packages if "all" is set
        if ($input->getOption('all')) {
            return $packageManager->getPackages()->getPackageNames();
        }

        $packageNames = array();

        // Display root if "root" option is given or if no option is set
        if ($input->getOption('root') || !$input->getOption('package')) {
            $packageNames[] = $packageManager->getRootPackage()->getName();
        }

        foreach ($input->getOption('package') as $packageName) {
            $packageNames[] = $packageName;
        }

        return $packageNames;
    }

    /**
     * @param OutputInterface   $output
     * @param ResourceMapping[] $mappings
     */
    private function printMappingTable(OutputInterface $output, array $mappings)
    {
        $table = new Table($output);
        $table->setStyle('compact');
        $table->getStyle()->setBorderFormat('');

        foreach ($mappings as $mapping) {
            $table->addRow(array(
                '<em>'.$mapping->getRepositoryPath().'</em>',
                ' '.implode(', ', $mapping->getFilesystemPaths())
            ));
        }

        $table->render();
    }
}

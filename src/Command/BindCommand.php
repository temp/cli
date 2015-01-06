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

use Puli\Cli\Util\StringUtil;
use Puli\RepositoryManager\Discovery\BindingDescriptor;
use Puli\RepositoryManager\Discovery\BindingState;
use Puli\RepositoryManager\Discovery\DiscoveryManager;
use Puli\RepositoryManager\ManagerFactory;
use Puli\RepositoryManager\Package\PackageManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Console\Command\Command;
use Webmozart\Console\Input\InputOption;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class BindCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('bind')
            ->setDescription('Bind resources to binding types')
            ->addArgument('resource-query', InputArgument::OPTIONAL, 'A query for resources')
            ->addArgument('type-name', InputArgument::OPTIONAL, 'The name of the binding type')
            ->addOption('root', null, InputOption::VALUE_NONE, 'Show bindings of the root package')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Show bindings of a package', null, 'package')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Show bindings of all packages')
            ->addOption('enabled', null, InputOption::VALUE_NONE, 'Show enabled bindings')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Show disabled bindings')
            ->addOption('undecided', null, InputOption::VALUE_NONE, 'Show bindings that are neither enabled nor disabled')
            ->addOption('held-back', null, InputOption::VALUE_NONE, 'Show bindings whose type is not loaded')
            ->addOption('ignored', null, InputOption::VALUE_NONE, 'Show bindings whose type is disabled')
            ->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete a binding')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Enable a binding')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Disable a binding')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'The language of the resource query', 'glob')
            ->addOption('param', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A binding parameter in the form <param>=<value>')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $logger = new ConsoleLogger($output);
        $environment = ManagerFactory::createProjectEnvironment(getcwd());
        $packageManager = ManagerFactory::createPackageManager($environment);
        $discoveryManager = ManagerFactory::createDiscoveryManager($environment, $packageManager, $logger);

        if ($input->getOption('delete')) {
            return $this->removeBinding(
                $input->getArgument('resource-query'),
                $discoveryManager
            );
        }

        if ($input->getArgument('resource-query')) {
            return $this->addBinding(
                $input->getArgument('resource-query'),
                $input->getOption('language'),
                $input->getArgument('type-name'),
                $input->getOption('param'),
                $discoveryManager
            );
        }

        $packageNames = $this->getPackageNames($input, $packageManager);
        $bindingStates = $this->getBindingStates($input, $discoveryManager);

        return $this->listBindings($output, $discoveryManager, $packageNames, $bindingStates);
    }

    private function addBinding($query, $language, $typeName, array $parameters, DiscoveryManager $discoveryManager)
    {
        $bindingParams = array();

        foreach ($parameters as $parameter) {
            $pos = strpos($parameter, '=');

            if (false === $pos) {
                // invalid parameter
            }

            $key = substr($parameter, 0, $pos);
            $value = StringUtil::parseValue(substr($parameter, $pos + 1));

            $bindingParams[$key] = $value;
        }

        $discoveryManager->addBinding($query, $typeName, $bindingParams, $language);

        return 0;
    }

    private function removeBinding($uuidPrefix, DiscoveryManager $discoveryManager)
    {
        $bindings = $discoveryManager->findBindings($uuidPrefix);

        if (0 === count($bindings)) {
            return 0;
        }

        if (count($bindings) > 1) {
            // ambiguous
        }

        $bindingToRemove = reset($bindings);
        $discoveryManager->removeBinding($bindingToRemove->getUuid());

        return 0;
    }

    /**
     * @param OutputInterface  $output
     * @param DiscoveryManager $discoveryManager
     * @param array            $packageNames
     *
     * @return int
     */
    private function listBindings(OutputInterface $output, DiscoveryManager $discoveryManager, array $packageNames, array $bindingStates)
    {
        $printBindingState = count($bindingStates) > 1;

        foreach ($bindingStates as $bindingState) {
            $bindingStatePrinted = !$printBindingState;

            foreach ($packageNames as $packageName) {
                $bindings = $discoveryManager->getBindings($packageName, $bindingState);

                if (!$bindings) {
                    continue;
                }

                if (!$bindingStatePrinted) {
                    $this->printBindingState($output, $bindingState);
                    $bindingStatePrinted = true;
                }

                $styleTag = BindingState::ENABLED === $bindingState ? null : 'fg=red';

                $this->printBindingTable($output, $bindings, $styleTag, $printBindingState);

                if ($printBindingState) {
                    $output->writeln('');
                }
            }
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

    private function getBindingStates(InputInterface $input)
    {
        $states = array();

        if ($input->getOption('enabled')) {
            $states[] = BindingState::ENABLED;
        }

        if ($input->getOption('disabled')) {
            $states[] = BindingState::DISABLED;
        }

        if ($input->getOption('undecided')) {
            $states[] = BindingState::UNDECIDED;
        }

        if ($input->getOption('held-back')) {
            $states[] = BindingState::HELD_BACK;
        }

        if ($input->getOption('ignored')) {
            $states[] = BindingState::IGNORED;
        }

        return $states ?: BindingState::all();
    }

    /**
     * @param OutputInterface     $output
     * @param BindingDescriptor[] $bindings
     */
    private function printBindingTable(OutputInterface $output, array $bindings, $styleTag = null, $indent = false)
    {
        $table = new Table($output);
        $table->setStyle('compact');
        $table->getStyle()->setBorderFormat('');

        $paramTag = $styleTag ?: 'comment';
        $uuidTag = $styleTag ?: 'comment';
        $queryTag = $styleTag ?: 'em';
        $typeTag = $styleTag ?: 'tt';

        foreach ($bindings as $binding) {
            $parameters = array();

            foreach ($binding->getParameters() as $parameter => $value) {
                $parameters[] = $parameter.'='.StringUtil::formatValue($value);
            }

            $uuid = substr($binding->getUuid(), 0, 6);
            $paramString = '('.implode(', ', $parameters).')';

            if ($indent) {
                $paramString = $paramString ? " <$paramTag>$paramString</$paramTag>" : '';

                $table->addRow(array(
                    "    <$uuidTag>".$uuid."</$uuidTag> ".
                    "<$queryTag>".$binding->getQuery()."</$queryTag>",
                    " <$typeTag>".$binding->getTypeName()."</$typeTag>".$paramString
                ));
            } else {
                $paramString = $paramString ? " $paramString" : '';

                $table->addRow(array(
                    "$uuid {$binding->getQuery()}",
                    " {$binding->getTypeName()}$paramString"
                ));
            }
        }

        $table->render();
    }

    private function printBindingState(OutputInterface $output, $bindingState)
    {
        switch ($bindingState) {
            case BindingState::ENABLED:
                $output->writeln('Enabled bindings:');
                $output->writeln('');
                return;
            case BindingState::DISABLED:
                $output->writeln('Disabled bindings:');
                $output->writeln(' (use "puli bind --enable <uuid>" to enable)');
                $output->writeln('');
                return;
            case BindingState::UNDECIDED:
                $output->writeln('Bindings that are neither enabled nor disabled:');
                $output->writeln(' (use "puli bind --enable <uuid>" to enable)');
                $output->writeln('');
                return;
            case BindingState::HELD_BACK:
                $output->writeln('The following bindings are held back:');
                $output->writeln(' (install their type definitions to enable them)');
                $output->writeln('');
                return;
            case BindingState::IGNORED:
                $output->writeln('The following bindings are ignored:');
                $output->writeln(' (resolve their duplicate type definitions to enable them)');
                $output->writeln('');
                return;
        }
    }
}
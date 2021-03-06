<?php
/**
 * @copyright (c) Copyright by authors of the Tiki Manager Project. All Rights Reserved.
 *     See copyright.txt for details and a complete list of authors.
 * @licence Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See LICENSE for details.
 */

namespace TikiManager\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TikiManager\Libs\Requirements\Requirements;

class CheckRequirementsCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('manager:check')
            ->setDescription('Check Tiki Manager requirements')
            ->setHelp('This command allows you to check if Tiki Manager requirements for server/client are met');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Requirements');

        $osReq = Requirements::getInstance();
        $requirements = $osReq->getRequirements();
        foreach ($requirements as $requirementKey => $requirement) {
            if ($osReq->check($requirementKey)) {
                $io->block($requirement['name'] . ' (' . $osReq->getTags($requirementKey) . ')', '<info>Ok</info>');
            } else {
                $io->block(ucfirst($osReq->getRequirementMessage($requirementKey)), '<error>missing</error>');
            }
        }
    }
}

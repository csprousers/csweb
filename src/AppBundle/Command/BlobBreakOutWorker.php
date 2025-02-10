<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AppBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use AppBundle\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use AppBundle\CSPro\DictionarySchemaHelper;

/**
 * Description of BlobBreakOutWorker
 *
 * @author savy
 */
class BlobBreakOutWorker extends Command {

    protected static $defaultName = 'csweb:blob-breakout-worker';

    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger) {
        parent::__construct();
    }

    protected function configure() {
        $this->setDescription('CSWeb blob breakout processing thread')
                ->addOption('dictionaryName', 'd', InputOption::VALUE_REQUIRED, 'Dictionary Name')
                ->addOption('jobId', 'j', InputOption::VALUE_REQUIRED, 'Job Id');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $dictName = $input->getOption('dictionaryName');
        $jobId = $input->getOption('jobId');
        $output->writeln('Thread started processing cases Dictionary: ' . $dictName . ' JobID: ' . $jobId);
        $this->logger->debug('Thread started processing cases for Dictionary: ' . $dictName . ' JobID: ' . $jobId);
        try {
            $dictionarySchemaHelper = new DictionarySchemaHelper($dictName, $this->pdo, $this->logger);
            $dictionarySchemaHelper->initialize();
            $processCasesOptions = $dictionarySchemaHelper->getProcessCaseOptions();
            $dictionarySchemaHelper->blobBreakOut($jobId, $processCasesOptions);
        } catch (\Exception $e) {
            $strMsg = 'Thread failed processing cases for Dictionary: ' . $dictName . 'JobID: ' . $jobId;
            $this->logger->error($strMsg, ["context" => (string) $e]);
        }
        $this->logger->debug('Thread completed processing cases for  Dictionary: ' . $dictName . ' JobID: ' . $jobId);
        $output->writeln('Thread completed processing cases for Dictionary: ' . $dictName . ' JobID: ' . $jobId);
        return 0;
    }

}

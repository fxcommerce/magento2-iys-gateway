<?php
declare(strict_types=1);

namespace FxCommerce\IysGateway\Console\Command;

use FxCommerce\IysGateway\Model\Gateway\Exporter;
use FxCommerce\IysGateway\Model\Gateway\InboundSynchronizer;
use FxCommerce\IysGateway\Model\QueuePublisher;
use Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCommand extends Command
{
    private const OPTION_FROM_ID = 'from-id';
    private const OPTION_STORE_ID = 'store-id';
    private const OPTION_LIMIT = 'limit';
    private const OPTION_EXPORT = 'export';
    private const OPTION_PULL = 'pull';

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly QueuePublisher $publisher,
        private readonly Exporter $exporter,
        private readonly InboundSynchronizer $inboundSynchronizer,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('fxcommerce:iys:sync')
            ->setDescription('Synchronize Magento email, SMS and call consents with IYS Gateway')
            ->addOption(self::OPTION_FROM_ID, null, InputOption::VALUE_OPTIONAL, 'Start after subscriber ID', '0')
            ->addOption(self::OPTION_STORE_ID, null, InputOption::VALUE_OPTIONAL, 'Filter by store ID')
            ->addOption(self::OPTION_LIMIT, null, InputOption::VALUE_OPTIONAL, 'Maximum records to queue', '0')
            ->addOption(
                self::OPTION_EXPORT,
                null,
                InputOption::VALUE_NONE,
                'Export all ready Magento records immediately, batch by batch'
            )
            ->addOption(
                self::OPTION_PULL,
                null,
                InputOption::VALUE_NONE,
                'Pull and apply all ready IYS decisions, batch by batch'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromId = max(0, (int)$input->getOption(self::OPTION_FROM_ID));
        $storeIdOption = $input->getOption(self::OPTION_STORE_ID);
        $storeId = $storeIdOption !== null ? (int)$storeIdOption : null;
        $limit = max(0, (int)$input->getOption(self::OPTION_LIMIT));

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('subscriber_id', ['gt' => $fromId]);
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', $storeId);
        }
        $collection->setOrder('subscriber_id', 'ASC');
        if ($limit > 0) {
            $collection->setPageSize($limit);
            $collection->setCurPage(1);
        }

        $scanned = 0;
        $queued = 0;
        foreach ($collection as $subscriber) {
            ++$scanned;
            if ($this->publisher->publish($subscriber)) {
                ++$queued;
            }
        }

        $output->writeln(sprintf(
            '<info>%d subscriber consent states were scanned; %d new queue records were added.</info>',
            $scanned,
            $queued
        ));

        if ($input->getOption(self::OPTION_EXPORT)) {
            $output->writeln('<comment>Exporting ready Magento records in batches...</comment>');
            $summary = $this->exporter->executeAll(
                $storeId,
                static function (array $batch) use ($output): void {
                    $message = sprintf(
                        'Store %s (#%d) - outbound batch %d: %d records exported, %d records remaining.',
                        (string)$batch['storeCode'],
                        (int)$batch['storeId'],
                        (int)$batch['batch'],
                        (int)$batch['exported'],
                        (int)$batch['remaining']
                    );
                    $output->writeln(
                        (int)$batch['exported'] > 0
                            ? sprintf('<info>%s</info>', $message)
                            : sprintf('<error>%s</error>', $message)
                    );
                }
            );

            $output->writeln(sprintf(
                '<info>Outbound completed: %d records exported in %d batches; %d records remaining.</info>',
                $summary['exported'],
                $summary['batches'],
                $summary['remaining']
            ));
            if ($summary['remaining'] > 0) {
                $output->writeln(sprintf(
                    '<comment>%d records are waiting for retry or require attention; %d are ready and %d are failed.</comment>',
                    $summary['remaining'],
                    $summary['ready'],
                    $summary['failed']
                ));
            }
        }

        if ($input->getOption(self::OPTION_PULL)) {
            if ($storeId === null || $storeId <= 0) {
                $output->writeln('<error>--pull requires a valid --store-id because inbound decisions are Store View scoped.</error>');
                return Command::INVALID;
            }
            $output->writeln('<comment>Pulling newer IYS decisions in batches...</comment>');
            $summary = $this->inboundSynchronizer->executeAll(
                $storeId,
                static function (array $batch) use ($output): void {
                    $output->writeln(sprintf(
                        '<info>Inbound batch %d: %d pulled, %d applied, %d failed.</info>',
                        $batch['batch'],
                        $batch['pulled'],
                        $batch['applied'],
                        $batch['failed']
                    ));
                }
            );
            $output->writeln(sprintf(
                '<info>Inbound completed: %d pulled, %d applied, %d failed in %d batches.</info>',
                $summary['pulled'],
                $summary['applied'],
                $summary['failed'],
                $summary['batches']
            ));
        }

        return Command::SUCCESS;
    }
}

<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Console\Command;

use CommerceGears\Smtp\Model\Cleaner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento commercegears:smtp:clean [--days=30]
 */
class CleanCommand extends Command
{
    public function __construct(
        private readonly Cleaner $cleaner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('commercegears:smtp:clean')
            ->setDescription('Deletes email log entries older than the retention period (or --days)')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete entries older than this many days (at least 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        if ($days !== null && (!ctype_digit((string)$days) || (int)$days < 1)) {
            $output->writeln('<error>--days must be a whole number of 1 or more.</error>');
            return Command::INVALID;
        }
        $deleted = $this->cleaner->clean($days !== null ? (int)$days : null);
        $output->writeln(sprintf('Deleted %d log entries.', $deleted));
        if ($days === null) {
            $output->writeln(sprintf('Deleted the stored content of %d emails.', $this->cleaner->cleanContent()));
        }
        return Command::SUCCESS;
    }
}

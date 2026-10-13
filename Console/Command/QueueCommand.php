<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Console\Command;

use CommerceGears\Smtp\Model\Mailer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento commercegears:smtp:queue [--limit=200]
 */
class QueueCommand extends Command
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('commercegears:smtp:queue')
            ->setDescription('Sends queued emails and retries that are due now (what the cron job does every minute)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of emails', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Exception) {
            // Area already set.
        }
        $stats = $this->mailer->processQueue(max(1, min(5000, (int)$input->getOption('limit'))));
        $output->writeln(sprintf(
            'Sent: %d, waiting to retry: %d, failed: %d, not sent: %d',
            $stats['sent'],
            $stats['retry'],
            $stats['failed'],
            $stats['skipped']
        ));
        return Command::SUCCESS;
    }
}

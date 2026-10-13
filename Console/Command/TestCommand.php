<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Console\Command;

use CommerceGears\Smtp\Model\Diagnostics\Tester;
use CommerceGears\Smtp\Model\Scope;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento commercegears:smtp:test --to=you@example.com [--store=1|--website=1] [--check-only] [--transcript]
 */
class TestCommand extends Command
{
    public function __construct(
        private readonly Tester $tester,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('commercegears:smtp:test')
            ->setDescription('Checks the SMTP connection or sends a test email with the saved settings')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient of the test email')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view ID (settings of that store view)')
            ->addOption('website', null, InputOption::VALUE_REQUIRED, 'Website ID (settings of that website)')
            ->addOption('check-only', null, InputOption::VALUE_NONE, 'Only connect and sign in, send nothing')
            ->addOption('transcript', null, InputOption::VALUE_NONE, 'Print the SMTP conversation (passwords hidden)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Exception) {
            // Area already set.
        }
        $scope = Scope::fromParams($input->getOption('store'), $input->getOption('website'));
        if ($input->getOption('check-only')) {
            $result = $this->tester->checkConnection($scope);
        } else {
            $to = (string)$input->getOption('to');
            if ($to === '') {
                $output->writeln('<error>Use --to=address to send a test email, or --check-only.</error>');
                return Command::INVALID;
            }
            $result = $this->tester->sendTest($scope, $to);
        }
        $output->writeln(($result['success'] ? '<info>OK</info> ' : '<error>FAILED</error> ') . $result['message']);
        if (!empty($result['hint'])) {
            $output->writeln('  ' . $result['hint']);
        }
        foreach ($result['warnings'] ?? [] as $warning) {
            $output->writeln('  <comment>' . $warning . '</comment>');
        }
        foreach ($result['details'] ?? [] as $label => $value) {
            $output->writeln(sprintf('  %-18s %s', $label . ':', $value));
        }
        if (!empty($result['technical'])) {
            $output->writeln('  Technical: ' . $result['technical']);
        }
        if ($input->getOption('transcript') && !empty($result['transcript'])) {
            $output->writeln('');
            $output->writeln($result['transcript']);
        }
        return $result['success'] ? Command::SUCCESS : Command::FAILURE;
    }
}

<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Console\Command;

use CommerceGears\Smtp\Model\Config;
use CommerceGears\Smtp\Model\Diagnostics\DnsChecker;
use CommerceGears\Smtp\Model\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento commercegears:smtp:dns [domain] [--selector=s1] [--store=1]
 */
class DnsCommand extends Command
{
    public function __construct(
        private readonly DnsChecker $checker,
        private readonly Config $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('commercegears:smtp:dns')
            ->setDescription('Checks SPF, DKIM, DMARC and MX of the sender domain')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain (default: the store sender\'s domain)')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED, 'DKIM selector(s), comma separated')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view ID (provider and sender of that store view)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scope = Scope::fromParams($input->getOption('store'), null);
        $domain = (string)($input->getArgument('domain') ?: $this->config->getStoreSenderEmail($scope));
        $checks = $this->checker->check($domain, $this->config->getPreset($scope), Config::splitList((string)$input->getOption('selector')));
        $problems = 0;
        foreach ($checks as $check) {
            $tag = match ($check['status']) {
                DnsChecker::OK => 'info',
                DnsChecker::ERROR => 'error',
                default => 'comment',
            };
            $problems += $check['status'] === DnsChecker::ERROR ? 1 : 0;
            $output->writeln(sprintf('<%s>%-6s %-8s</%s> %s', $tag, $check['check'], strtoupper($check['status']), $tag, $check['message']));
            if ($check['record'] !== '') {
                $output->writeln('                ' . $check['record']);
            }
        }
        return $problems > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

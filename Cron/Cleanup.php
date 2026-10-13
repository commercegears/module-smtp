<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Cron;

use CommerceGears\Smtp\Model\Cleaner;

class Cleanup
{
    public function __construct(
        private readonly Cleaner $cleaner
    ) {
    }

    public function execute(): void
    {
        $this->cleaner->clean();
        $this->cleaner->cleanContent();
    }
}

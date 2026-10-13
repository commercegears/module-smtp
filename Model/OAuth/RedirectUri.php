<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\OAuth;

use Magento\Backend\Model\UrlInterface;

/**
 * The fixed admin address Google and Microsoft send the admin back to after signing in. It has no secret key, so it
 * can be registered once; the callback still requires an admin session and a matching state.
 */
class RedirectUri
{
    public const ROUTE = 'commercegears_smtp/oauth/callback';

    public function __construct(
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function get(): string
    {
        $url = $this->backendUrl->getUrl(self::ROUTE, ['_nosecret' => true, '_secure' => true]);
        return (string)strtok($url, '?');
    }
}

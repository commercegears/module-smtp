<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Smtp;

/**
 * An SMTP error together with the (masked) transcript of the dialogue that led to it.
 */
class TransportFailure extends SmtpException
{
    public function __construct(SmtpException $error, private readonly string $transcript)
    {
        parent::__construct($error->getMessage(), $error->getStage(), $error->getReplyCode(), $error->getReplyText(), $error);
    }

    public function getTranscript(): string
    {
        return $this->transcript;
    }
}

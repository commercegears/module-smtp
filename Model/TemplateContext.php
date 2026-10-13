<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model;

/**
 * Remembers which email template and store view a transport was built for, so the log shows the email type and
 * the right store's SMTP account is used even for emails sent from the admin. Weak maps: nothing leaks.
 */
class TemplateContext
{
    /** @var \WeakMap<object, array{template: ?string, store: ?int}> */
    private \WeakMap $pending;
    /** @var \WeakMap<object, array{template: ?string, store: ?int}> */
    private \WeakMap $transports;

    public function __construct()
    {
        $this->pending = new \WeakMap();
        $this->transports = new \WeakMap();
    }

    public function setTemplate(object $builder, ?string $templateId): void
    {
        $entry = $this->pending[$builder] ?? ['template' => null, 'store' => null];
        $entry['template'] = $templateId;
        $this->pending[$builder] = $entry;
    }

    public function setStore(object $builder, ?int $storeId): void
    {
        $entry = $this->pending[$builder] ?? ['template' => null, 'store' => null];
        $entry['store'] = $storeId;
        $this->pending[$builder] = $entry;
    }

    /**
     * Moves what was collected on the builder over to the transport it just created.
     */
    public function bind(object $builder, object $transport): void
    {
        if (isset($this->pending[$builder])) {
            $this->transports[$transport] = $this->pending[$builder];
            unset($this->pending[$builder]);
        }
    }

    /**
     * @return array{template: ?string, store: ?int}
     */
    public function get(object $transport): array
    {
        return $this->transports[$transport] ?? ['template' => null, 'store' => null];
    }
}

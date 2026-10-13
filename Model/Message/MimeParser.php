<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Message;

/**
 * Reads the HTML part, the text part and the attachment list out of a raw MIME message, for the log view.
 */
class MimeParser
{
    private const MAX_DEPTH = 8;

    /**
     * @return array{html: string, text: string, attachments: array<int, array{name: string, type: string, size: int}>}
     */
    public function parse(string $raw): array
    {
        $result = ['html' => '', 'text' => '', 'attachments' => []];
        $this->walk(new MimeMessage($raw), $result, 0);
        return $result;
    }

    /**
     * @param array{html: string, text: string, attachments: array<int, array{name: string, type: string, size: int}>} $result
     */
    private function walk(MimeMessage $part, array &$result, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        [$type, $params] = $this->parseParams((string)($part->get('Content-Type') ?? 'text/plain'));
        $type = strtolower($type);
        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                return;
            }
            foreach ($this->splitMultipart($part->getBody(), $boundary) as $child) {
                $this->walk(new MimeMessage($child), $result, $depth + 1);
            }
            return;
        }
        if ($type === 'message/rfc822') {
            $this->walk(new MimeMessage($part->getBody()), $result, $depth + 1);
            return;
        }

        [$disposition, $dispositionParams] = $this->parseParams((string)($part->get('Content-Disposition') ?? ''));
        $filename = $dispositionParams['filename'] ?? $params['name'] ?? '';
        $content = $this->decodeBody($part->getBody(), strtolower((string)$part->get('Content-Transfer-Encoding')));
        $isAttachment = strtolower($disposition) === 'attachment' || ($filename !== '' && !in_array($type, ['text/html', 'text/plain'], true));

        if (!$isAttachment && $type === 'text/html' && $result['html'] === '') {
            $result['html'] = $this->toUtf8($content, $params['charset'] ?? '');
            return;
        }
        if (!$isAttachment && $type === 'text/plain' && $result['text'] === '') {
            $result['text'] = $this->toUtf8($content, $params['charset'] ?? '');
            return;
        }
        if ($isAttachment || $filename !== '' || !str_starts_with($type, 'text/')) {
            $result['attachments'][] = [
                'name' => $filename !== '' ? MimeMessage::decode($filename) : '(' . $type . ')',
                'type' => $type,
                'size' => strlen($content),
            ];
        }
    }

    /**
     * @return string[]
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        $parts = [];
        $delimiter = '--' . $boundary;
        $lines = explode("\r\n", $body);
        $current = null;
        foreach ($lines as $line) {
            if (rtrim($line) === $delimiter . '--') {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }
                $current = null;
                break;
            }
            if (rtrim($line) === $delimiter) {
                if ($current !== null) {
                    $parts[] = implode("\r\n", $current);
                }
                $current = [];
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null && $current !== []) {
            $parts[] = implode("\r\n", $current);
        }
        return $parts;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string)base64_decode((string)preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    private function toUtf8(string $content, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        try {
            $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = false;
        }
        return is_string($converted) ? $converted : $content;
    }

    /**
     * "text/html; charset=utf-8; name=\"a.pdf\"" => ['text/html', ['charset' => 'utf-8', 'name' => 'a.pdf']]
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseParams(string $value): array
    {
        $segments = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $main = trim((string)array_shift($segments));
        $params = [];
        foreach ($segments as $segment) {
            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $key = strtolower(trim($pair[0]));
            $val = trim(trim($pair[1]), '"');
            if (str_ends_with($key, '*')) {
                // RFC 2231: filename*=utf-8''name.pdf
                $key = rtrim($key, '*');
                if (preg_match("/^[^']*'[^']*'(.*)$/", $val, $m) === 1) {
                    $val = rawurldecode($m[1]);
                }
            }
            $params[$key] = $val;
        }
        return [$main, $params];
    }
}

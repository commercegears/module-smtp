<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Message;

/**
 * Parses and formats RFC 5322 address lists ("Name" <a@b>, c@d, Group: e@f;).
 */
class AddressParser
{
    /**
     * @return array<int, array{email: string, name: string}>
     */
    public function parse(string $value): array
    {
        $result = [];
        foreach ($this->split($value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $email = '';
            $name = '';
            if (preg_match('/^(.*)<([^<>]*)>\s*$/s', $part, $m) === 1) {
                $email = trim($m[2]);
                $name = $this->cleanName($m[1]);
            } else {
                $email = trim((string)preg_replace('/\([^)]*\)/', '', $part));
            }
            if ($email !== '' && str_contains($email, '@')) {
                $result[] = ['email' => $email, 'name' => $name];
            }
        }
        return $result;
    }

    /**
     * @return string[]
     */
    public function parseEmails(string $value): array
    {
        return array_map(static fn (array $a): string => $a['email'], $this->parse($value));
    }

    /**
     * @param array<int, array{email: string, name?: string}|string> $addresses
     */
    public function format(array $addresses): string
    {
        $parts = [];
        foreach ($addresses as $address) {
            if (is_string($address)) {
                $address = ['email' => $address, 'name' => ''];
            }
            $email = str_replace(["\r", "\n", '<', '>', ','], '', trim($address['email']));
            $name = trim(str_replace(["\r", "\n"], ' ', (string)($address['name'] ?? '')));
            if ($email === '') {
                continue;
            }
            $parts[] = $name === '' ? $email : $this->encodeName($name) . ' <' . $email . '>';
        }
        return implode(', ', $parts);
    }

    public function encodeName(string $name): string
    {
        if (preg_match('/[\x80-\xFF]/', $name) === 1) {
            return mb_encode_mimeheader($name, 'UTF-8', 'Q', "\r\n");
        }
        if (preg_match('/[()<>\[\]:;@\\\\,."]/', $name) === 1) {
            return '"' . addcslashes($name, '"\\') . '"';
        }
        return $name;
    }

    /**
     * Splits on commas that are outside quotes, angle brackets and comments; drops group names.
     *
     * @return string[]
     */
    private function split(string $value): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $angle = 0;
        $comment = 0;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($inQuote) {
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $char . $value[++$i];
                    continue;
                }
                if ($char === '"') {
                    $inQuote = false;
                }
                $current .= $char;
                continue;
            }
            switch ($char) {
                case '"':
                    $inQuote = true;
                    $current .= $char;
                    break;
                case '(':
                    $comment++;
                    $current .= $char;
                    break;
                case ')':
                    $comment = max(0, $comment - 1);
                    $current .= $char;
                    break;
                case '<':
                    $angle++;
                    $current .= $char;
                    break;
                case '>':
                    $angle = max(0, $angle - 1);
                    $current .= $char;
                    break;
                case ':':
                    if ($angle === 0 && $comment === 0) {
                        // Group syntax: the text before the colon is the group's name.
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                    break;
                case ';':
                case ',':
                    if ($angle === 0 && $comment === 0) {
                        $parts[] = $current;
                        $current = '';
                    } else {
                        $current .= $char;
                    }
                    break;
                default:
                    $current .= $char;
            }
        }
        $parts[] = $current;
        return $parts;
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if (str_starts_with($name, '"') && str_ends_with($name, '"') && strlen($name) >= 2) {
            $name = stripcslashes(substr($name, 1, -1));
        }
        if (str_contains($name, '=?')) {
            $decoded = @iconv_mime_decode($name, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if (is_string($decoded)) {
                $name = $decoded;
            }
        }
        return trim($name);
    }
}

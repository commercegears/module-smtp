<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_Smtp
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\Smtp\Model\Message;

/**
 * A raw MIME message split into its header lines and body. Headers that are not touched keep their original bytes;
 * changed headers are written again. The body is never changed.
 */
class MimeMessage
{
    /** @var array<int, array{name: string, raw: string}> */
    private array $headers = [];
    private string $body = '';

    public function __construct(string $raw)
    {
        $raw = (string)preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
        $position = strpos($raw, "\r\n\r\n");
        if ($position === false) {
            $headerBlock = $raw;
            $this->body = '';
        } else {
            $headerBlock = substr($raw, 0, $position);
            $this->body = substr($raw, $position + 4);
        }
        foreach (explode("\r\n", $headerBlock) as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $this->headers !== []) {
                $this->headers[count($this->headers) - 1]['raw'] .= "\r\n" . $line;
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $this->headers[] = ['name' => trim(substr($line, 0, $colon)), 'raw' => $line];
        }
    }

    public function has(string $name): bool
    {
        return $this->get($name) !== null;
    }

    /**
     * Unfolded value of the first header with this name (still MIME-encoded), or null.
     */
    public function get(string $name): ?string
    {
        foreach ($this->headers as $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                return self::valueOf($header['raw']);
            }
        }
        return null;
    }

    /**
     * Decoded (UTF-8) value of the first header with this name.
     */
    public function getDecoded(string $name): string
    {
        $value = $this->get($name);
        if ($value === null) {
            return '';
        }
        return self::decode($value);
    }

    public function remove(string $name): void
    {
        $this->headers = array_values(array_filter(
            $this->headers,
            static fn (array $header): bool => strcasecmp($header['name'], $name) !== 0
        ));
    }

    /**
     * Replaces the header (keeps the position of the first one) or adds it at the end. $value must already be
     * encoded (ASCII); long address lists are folded.
     */
    public function set(string $name, string $value): void
    {
        $line = self::fold($name . ': ' . str_replace(["\r", "\n"], '', $value));
        foreach ($this->headers as $index => $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                $this->headers[$index] = ['name' => $name, 'raw' => $line];
                $first = $index;
                $this->headers = array_values(array_filter(
                    $this->headers,
                    static fn (array $h, int $i): bool => $i === $first || strcasecmp($h['name'], $name) !== 0,
                    ARRAY_FILTER_USE_BOTH
                ));
                return;
            }
        }
        $this->headers[] = ['name' => $name, 'raw' => $line];
    }

    /**
     * Sets an unstructured text header (Subject), MIME-encoding it when it is not plain ASCII.
     */
    public function setText(string $name, string $value): void
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            $encoded = mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n", strlen($name) + 2);
            $this->setRaw($name, $name . ': ' . $encoded);
            return;
        }
        $this->set($name, $value);
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<int, array{name: string, value: string}>
     */
    public function getHeaderList(): array
    {
        return array_map(
            static fn (array $header): array => ['name' => $header['name'], 'value' => self::valueOf($header['raw'])],
            $this->headers
        );
    }

    public function toString(): string
    {
        $lines = array_map(static fn (array $header): string => $header['raw'], $this->headers);
        return implode("\r\n", $lines) . "\r\n\r\n" . $this->body;
    }

    public static function decode(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return $value;
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
        return mb_decode_mimeheader($value);
    }

    private function setRaw(string $name, string $line): void
    {
        foreach ($this->headers as $index => $header) {
            if (strcasecmp($header['name'], $name) === 0) {
                $this->remove($name);
                array_splice($this->headers, min($index, count($this->headers)), 0, [['name' => $name, 'raw' => $line]]);
                return;
            }
        }
        $this->headers[] = ['name' => $name, 'raw' => $line];
    }

    private static function valueOf(string $raw): string
    {
        $colon = strpos($raw, ':');
        $value = $colon === false ? '' : substr($raw, $colon + 1);
        return trim((string)preg_replace("/\r\n([ \t])/", '$1', $value));
    }

    /**
     * Folds a header line at ", " so no line is longer than 900 characters.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 900) {
            return $line;
        }
        $parts = explode(', ', $line);
        $result = '';
        $current = '';
        foreach ($parts as $index => $part) {
            $piece = $index < count($parts) - 1 ? $part . ',' : $part;
            if ($current !== '' && strlen($current) + strlen($piece) + 1 > 900) {
                $result .= $current . "\r\n";
                $current = ' ' . $piece;
            } else {
                $current .= ($current === '' ? '' : ' ') . $piece;
            }
        }
        return $result . $current;
    }
}

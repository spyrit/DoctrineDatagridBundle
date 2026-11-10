<?php

namespace Spyrit\Bundle\DoctrineDatagridBundle\Datagrid\Export;

/**
 * In-memory CSV writer.
 *
 * Drop-in replacement for csanquer/colibri-csv (abandoned): it accepts the same
 * options, with the same defaults ("excel" dialect), and produces the same output.
 */
final class CsvWriter
{
    public const ENCLOSING_ALL = 'all';
    public const ENCLOSING_MINIMAL = 'minimal';
    public const ENCLOSING_NONNUMERIC = 'nonnumeric';

    private const DEFAULT_OPTIONS = [
        'delimiter' => ';',
        'enclosure' => '"',
        'enclosing_mode' => self::ENCLOSING_MINIMAL,
        'encoding' => 'CP1252',
        'eol' => "\r\n",
        'escape' => '\\',
        'escape_double' => true,
        'bom' => false,
        'translit' => 'translit',
        'first_row_header' => false,
        'trim' => false,
    ];

    private const LINE_ENDINGS = [
        'unix' => "\n",
        'linux' => "\n",
        "\n" => "\n",
        'mac' => "\r",
        'macos' => "\r",
        "\r" => "\r",
    ];

    private const BOMS = [
        'UTF-8' => "\xEF\xBB\xBF",
        'UTF-16BE' => "\xFE\xFF",
        'UTF-16LE' => "\xFF\xFE",
        'UTF-32BE' => "\x00\x00\xFE\xFF",
        'UTF-32LE' => "\xFF\xFE\x00\x00",
    ];

    private string $delimiter;
    private string $enclosure;
    private string $enclosingMode;
    private string $encoding;
    private string $eol;
    private string $escape;
    private bool $escapeDouble;
    private ?string $translit;
    private bool $firstRowHeader;
    private bool $trim;

    private string $content = '';
    private bool $headerWritten = false;

    /**
     * @param array<string, mixed> $options Same options as colibri-csv: delimiter, enclosure,
     *                                      enclosing_mode (all|minimal|nonnumeric), encoding, eol
     *                                      (unix|linux|mac|macos|windows|win or the characters),
     *                                      escape, escape_double, bom, translit (translit|ignore|null),
     *                                      first_row_header, trim. Reader-only options are ignored.
     */
    public function __construct(array $options = [])
    {
        $options = array_merge(self::DEFAULT_OPTIONS, array_change_key_case($options, \CASE_LOWER));

        // Empty values fall back to the defaults, like colibri-csv
        $this->delimiter = (string) ($options['delimiter'] ?: self::DEFAULT_OPTIONS['delimiter']);
        $this->enclosure = (string) ($options['enclosure'] ?: self::DEFAULT_OPTIONS['enclosure']);
        $this->encoding = (string) ($options['encoding'] ?: self::DEFAULT_OPTIONS['encoding']);
        $this->escape = (string) ($options['escape'] ?: self::DEFAULT_OPTIONS['escape']);
        $this->enclosingMode = \in_array($options['enclosing_mode'], [self::ENCLOSING_ALL, self::ENCLOSING_MINIMAL, self::ENCLOSING_NONNUMERIC], true)
            ? $options['enclosing_mode']
            : self::ENCLOSING_MINIMAL;
        $this->eol = self::LINE_ENDINGS[$options['eol']] ?? "\r\n";
        $this->escapeDouble = (bool) $options['escape_double'];
        $translit = strtolower((string) $options['translit']);
        $this->translit = \in_array($translit, ['translit', 'ignore'], true) ? $translit : null;
        $this->firstRowHeader = (bool) $options['first_row_header'];
        $this->trim = (bool) $options['trim'];

        if ($options['bom']) {
            $this->content = self::BOMS[$this->encoding] ?? '';
        }
    }

    /**
     * @param array<array-key, mixed> $values Scalars, null or \Stringable objects
     */
    public function writeRow(array $values): self
    {
        if ($this->firstRowHeader && !$this->headerWritten) {
            $this->headerWritten = true;
            $this->write(array_keys($values));
        }

        $this->write($values);

        return $this;
    }

    /**
     * @param iterable<array<array-key, mixed>> $rows
     */
    public function writeRows(iterable $rows): self
    {
        foreach ($rows as $values) {
            $this->writeRow($values);
        }

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function write(array $values): void
    {
        $line = implode($this->delimiter, array_map($this->formatField(...), $values)).$this->eol;

        $this->content .= $this->transcode($line);
    }

    private function formatField(mixed $value): string
    {
        $value = match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '',
            \is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(\sprintf('A CSV field must be a scalar, null or a \Stringable object, "%s" given.', get_debug_type($value))),
        };

        if ($this->trim) {
            $value = trim($value);
        }

        $value = str_replace(
            $this->enclosure,
            ($this->escapeDouble ? $this->enclosure : $this->escape).$this->enclosure,
            $value,
        );

        $mustEnclose = match ($this->enclosingMode) {
            self::ENCLOSING_ALL => true,
            self::ENCLOSING_NONNUMERIC => 1 === preg_match('/[^\d\.]+/', $value),
            default => 1 === preg_match('/['.preg_quote($this->enclosure.$this->delimiter.$this->eol, '/').']+/', $value),
        };

        return $mustEnclose ? $this->enclosure.$value.$this->enclosure : $value;
    }

    private function transcode(string $line): string
    {
        if ('UTF-8' === $this->encoding) {
            return $line;
        }

        if (\function_exists('iconv')) {
            $target = $this->encoding.(null !== $this->translit ? '//'.strtoupper($this->translit) : '');

            set_error_handler(static fn (int $type, string $message): never => throw new \UnexpectedValueException(\sprintf('Unable to convert the CSV line to "%s": %s', $target, $message)));
            try {
                $result = iconv('UTF-8', $target, $line);
            } finally {
                restore_error_handler();
            }

            if (false === $result) {
                throw new \UnexpectedValueException(\sprintf('Unable to convert the CSV line to "%s".', $target));
            }

            return $result;
        }

        // mbstring only knows Windows code pages by their "Windows-125x" name
        $encoding = preg_replace('/^CP(125[0-8])$/i', 'Windows-$1', $this->encoding);

        return mb_convert_encoding($line, $encoding, 'UTF-8');
    }
}

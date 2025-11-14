<?php
require __DIR__ . '/../modules/bootstrap.php';
balp_include_module_include('vzornik_ral', 'helpers');

class VzornikRalDummyStatement extends PDOStatement
{
    private array $rows = [];
    private int $index = 0;

    public static function withRows(array $rows): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $stmt */
        $stmt = $reflection->newInstanceWithoutConstructor();
        $stmt->rows = array_values($rows);
        $stmt->index = 0;
        return $stmt;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($this->index >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->index++];
        if ($mode === PDO::FETCH_NUM) {
            return array_values($row);
        }
        return $row;
    }
}

class VzornikRalDummyPDO extends PDO
{
    private array $columns;

    public function __construct(array $columns)
    {
        $this->columns = $columns;
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SHOW COLUMNS') === 0) {
            return VzornikRalDummyStatement::withRows($this->columns);
        }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}

function assertSameOrFail(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual:   ' . var_export($actual, true));
    }
}

$columns = [
    ['Field' => 'IDRAL'],
    ['Field' => 'CISLO'],
    ['Field' => 'NAZEV'],
    ['Field' => 'HEX_KOD'],
    ['Field' => 'RGB_TXT'],
    ['Field' => 'RGB_R'],
    ['Field' => 'RGB_G'],
    ['Field' => 'RGB_B'],
];
$pdo = new VzornikRalDummyPDO($columns);

$row = [
    'IDRAL' => '789',
    'CISLO' => ' 1000 ',
    'NAZEV' => "Žlutá  ",
    'HEX_KOD' => '#1a2',
    'RGB_TXT' => '15, 25, 35',
    'RGB_R' => '100',
    'RGB_G' => '110',
    'RGB_B' => '120',
];

$result = balp_ral_normalize_row($pdo, $row);

assertSameOrFail(789, $result['id'], 'ID should be parsed as integer even with uppercase column names.');
assertSameOrFail('1000', $result['cislo'], 'Code should be trimmed and returned.');
assertSameOrFail('Žlutá', $result['nazev'], 'Name should be trimmed and stay readable.');
assertSameOrFail('#11AA22', $result['hex'], 'Hex should be normalised and uppercased.');
assertSameOrFail('15, 25, 35', $result['rgb'], 'RGB textual value should be preserved.');
assertSameOrFail([15, 25, 35], $result['rgb_components'], 'RGB components should be parsed from the textual field.');
assertSameOrFail('#11AA22', $result['color'], 'Colour fallback should prefer the hex representation.');

$rowWithoutHex = [
    'IDRAL' => '790',
    'CISLO' => '1001',
    'NAZEV' => 'Modrá',
    'HEX_KOD' => null,
    'RGB_TXT' => '',
    'RGB_R' => '1',
    'RGB_G' => '2',
    'RGB_B' => '3',
];

$resultWithoutHex = balp_ral_normalize_row($pdo, $rowWithoutHex);

assertSameOrFail('#010203', $resultWithoutHex['color'], 'Colour should fall back to RGB components when hex is missing.');
assertSameOrFail([1, 2, 3], $resultWithoutHex['rgb_components'], 'RGB components should be taken from individual columns.');

echo "OK\n";

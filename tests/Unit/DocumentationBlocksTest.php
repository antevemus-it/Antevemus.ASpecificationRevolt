<?php

declare(strict_types=1);

namespace Antevemus\ASpecificationRevolt\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The code blocks of the documentation are tests: every PHP block of docs/en and docs/pt-BR (and the Quick
 * Start of each README) runs here, as written, and every answer written in it is checked.
 *
 * A tested block is a ```php block preceded by the marker `<!-- tested: <id> -->`; DocumentationTreeTest
 * requires the marker on every PHP block, so none escapes. Each block is read from its page and executed in
 * its own namespace (a leading `<?php` is dropped and `declare(strict_types=1);` is kept ahead of the
 * namespace). A one-line statement followed by a comment that is a PHP literal (`// [1, 3]`, `// 'b'`,
 * `// true`), or a scalar literal followed by a remark in parentheses (`// true (the waits overlapped)`),
 * asserts that the statement evaluates to that literal; a `// Result: <literal>` (`// Resultado:`) line
 * asserts the variable assigned last; `// throws Class: message` asserts the class (short name) and the
 * message of the exception. Any other comment is prose. The reader is the one of antevemus/alinq-revolt 1.0.0
 * (from ALinq's ReadmeConsumerGapsTest, antevemus/alinq-collection 2.2.1), with the marker required on every
 * block.
 *
 * @version    1.0.0
 * @package    Antevemus\ASpecificationRevolt\Tests
 * @subpackage Unit
 */
final class DocumentationBlocksTest extends TestCase
{
    /** The tested blocks and the number of answers each one writes down. */
    public const BLOCKS = [
        'quick-start' => 2,
        'stage' => 4,
        'blocking' => 2,
        'closure' => 4,
        'failures' => 6,
        'transport' => 5,
        'fiber' => 2,
    ];

    /** The page of docs/<language> that hosts each tested block. */
    public const PAGES = [
        'quick-start' => 'getting-started/index',
        'stage' => 'concepts/how-it-works',
        'blocking' => 'concepts/how-it-works',
        'closure' => 'api/revolt-data-source',
        'failures' => 'api/revolt-data-source',
        'transport' => 'api/revolt-data-source',
        'fiber' => 'api/revolt-data-source',
    ];

    /** The README of each language: its only block is the Quick Start, run here too. */
    private const READMES = ['en' => 'README.md', 'pt-BR' => 'README.pt-BR.md'];

    /**
     * @return array<string, array{string, string}>
     */
    public static function blocks(): array
    {
        $cases = [];
        foreach (array_keys(self::READMES) as $language) {
            foreach (self::PAGES as $id => $page) {
                $file = "docs/$language/$page.md";
                $cases["$file $id"] = [$file, $id];
            }
        }
        foreach (self::READMES as $readme) {
            $cases["$readme quick-start"] = [$readme, 'quick-start'];
        }

        return $cases;
    }

    /**
     * @return array<string, string> id => code
     */
    public static function markedBlocks(string $file): array
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);
        preg_match_all('/<!-- tested: ([\w-]+) -->\s*```php\n(.*?)```/s', $text, $matches, PREG_SET_ORDER);
        $blocks = [];
        foreach ($matches as [, $id, $code]) {
            $blocks[$id] = $code;
        }

        return $blocks;
    }

    #[DataProvider('blocks')]
    public function testTheBlockRunsAndAnswersAsWritten(string $file, string $id): void
    {
        $blocks = self::markedBlocks($file);
        $this->assertArrayHasKey($id, $blocks, "$file has the tested block $id");
        [$code, $expectations] = self::instrument($blocks[$id]);
        $results = self::execute($code, $file, $id);

        $this->assertCount(self::BLOCKS[$id], $expectations, "$file $id: the answers written in the block");
        $this->assertCount(count($expectations), $results, "$file $id: every answer evaluated");
        foreach ($expectations as $i => [$expected, $line]) {
            $this->assertSame($expected, $results[$i], "$file $id: $line");
        }
    }

    public function testBothLanguagesCarryTheSameBlocks(): void
    {
        $english = self::language('en');
        $portuguese = self::language('pt-BR');

        $this->assertSame(array_keys(self::BLOCKS), array_keys(self::PAGES), 'every tested block has its page');
        $this->assertSame(array_keys(self::BLOCKS), array_keys($english), 'docs/en: the tested blocks, in order');
        $this->assertSame(array_keys(self::BLOCKS), array_keys($portuguese), 'docs/pt-BR: the tested blocks, in order');
        foreach (self::READMES as $language => $readme) {
            $this->assertSame(['quick-start' => ($language === 'en' ? $english : $portuguese)['quick-start']], self::markedBlocks($readme), "$readme: its only tested block is the Quick Start of docs/$language/getting-started/index.md, byte by byte");
        }
        foreach ($english as $id => $code) {
            $this->assertSame(self::codeTokens($code), self::codeTokens($portuguese[$id]), "block $id: the same code in both languages, comments aside");
            $this->assertSame(array_column(self::instrument($code)[1], 0), array_column(self::instrument($portuguese[$id])[1], 0), "block $id: the same answers in both languages");
        }
    }

    /**
     * The tested blocks of every page of docs/<language>, in the order of PAGES; each page carries
     * exactly the markers declared for it.
     *
     * @return array<string, string> id => code
     */
    private static function language(string $language): array
    {
        $blocks = [];
        foreach (array_unique(self::PAGES) as $page) {
            $found = self::markedBlocks("docs/$language/$page.md");
            $declared = array_keys(self::PAGES, $page, true);
            self::assertSame($declared, array_keys($found), "docs/$language/$page.md: the tested blocks declared for the page");
            $blocks += $found;
        }

        return array_merge(array_flip(array_keys(self::PAGES)), $blocks);
    }

    /**
     * Rewrites a block so that every written answer is recorded in $__results.
     *
     * @return array{string, list<array{mixed, string}>} [code, [[expected, original line], ...]]
     */
    private static function instrument(string $block): array
    {
        $lines = explode("\n", $block);
        $expectations = [];
        $lastAssigned = null;
        foreach ($lines as $n => $line) {
            if (preg_match('/^\s*\/\/\s*(?:Result|Resultado):\s*(.+?)\s*$/', $line, $r) && $lastAssigned !== null) {
                $expected = self::literal($r[1]);
                if ($expected !== null) {
                    $lines[$n] = '$__results[] = ' . $lastAssigned . ';';
                    $expectations[] = [$expected[0], trim($line)];
                }
                continue;
            }
            if (preg_match('/^(\$\w+)\s*=/', $line, $a)) {
                $lastAssigned = $a[1];
            }
            if (!preg_match('/^(\S.*?);\s*\/\/\s*(.+?)\s*$/', $line, $m)) {
                continue;
            }
            [, $statement, $comment] = $m;
            if (preg_match('/^throws (\w+): (.+)$/', $comment, $t)) {
                $lines[$n] = 'try { ' . $statement . '; $__results[] = "no exception"; } catch (\Throwable $__e) { $__results[] = [(new \ReflectionClass($__e))->getShortName(), $__e->getMessage()]; }';
                $expectations[] = [[$t[1], $t[2]], trim($line)];
                continue;
            }
            $expected = self::literal($comment);
            if ($expected === null) {
                continue; // prose
            }
            $lines[$n] = '$__results[] = ' . $statement . ';';
            $expectations[] = [$expected[0], trim($line)];
        }

        return [implode("\n", $lines), $expectations];
    }

    /**
     * The value of a written answer, wrapped in a one-element array, or null when the comment is prose.
     *
     * @return array{mixed}|null
     */
    private static function literal(string $comment): ?array
    {
        if (preg_match('/^(\'[^\']*\'|-?\d+(?:\.\d+)?|true|false|null)\s+\(.*\)$/', $comment, $m)) {
            $comment = $m[1];
        }
        if (!preg_match('/^(\[.*\]|\'.*\'|-?\d+(?:\.\d+)?|true|false|null)$/', $comment)) {
            return null;
        }
        try {
            return [eval("return $comment;")];
        } catch (\ParseError) {
            return null;
        }
    }

    /**
     * Runs a block in its own namespace and returns what it recorded.
     *
     * @return list<mixed>
     */
    private static function execute(string $code, string $file, string $id): array
    {
        $namespace = 'Antevemus\\ASpecificationRevolt\\Tests\\Docs\\' . preg_replace('/\W/', '_', $file) . '_' . preg_replace('/\W/', '_', $id);
        $code = (string) preg_replace('/^\s*<\?php\s*/', '', $code);
        $declare = '';
        if (preg_match('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $code, $d)) {
            $declare = $d[0] . "\n";
            $code = substr($code, strlen($d[0]));
        }
        $__results = [];
        try {
            eval($declare . "namespace $namespace;\n" . $code);
        } catch (Throwable $e) {
            throw new \RuntimeException("$file block $id threw " . $e::class . ': ' . $e->getMessage(), 0, $e);
        }

        return $__results;
    }

    /**
     * The code tokens of a block, without comments and whitespace.
     *
     * @return list<string>
     */
    public static function codeTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all('<?php ' . $code) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE, T_OPEN_TAG], true)) {
                    continue;
                }
                $tokens[] = $token[1];
            } else {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }
}

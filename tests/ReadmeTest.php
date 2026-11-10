<?php

declare(strict_types=1);

namespace Reeds\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every PHP block in the README parses. (tests/Readme/Examples.php holds the
 * longer ones as real code, which PHPStan checks against the library's types.)
 */
final class ReadmeTest extends TestCase
{
    public function testEveryPhpBlockInTheReadmeParses(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        preg_match_all('/```php\n(.*?)```/s', $readme, $blocks);
        self::assertGreaterThan(15, count($blocks[1]));
        foreach ($blocks[1] as $i => $block) {
            $code = str_starts_with($block, '<?php') ? $block : "<?php\n" . $block;
            try {
                token_get_all($code, TOKEN_PARSE);
            } catch (\ParseError) {
                // A fragment of an array (config/services.php) parses inside one.
                try {
                    token_get_all("<?php\n[\n" . $block . "\n];", TOKEN_PARSE);
                } catch (\ParseError $error) {
                    self::fail("README block {$i} doesn't parse: {$error->getMessage()}\n{$block}");
                }
            }
        }
    }
}

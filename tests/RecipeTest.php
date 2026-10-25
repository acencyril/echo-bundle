<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Flex clears the cache right after installing: the recipe's configuration must be valid as copied. */
final class RecipeTest extends TestCase
{
    private const DIR = __DIR__.'/../recipe';

    public function testTheRecipeConfigurationIsValid(): void
    {
        $config = ConfigurationTest::process(Yaml::parseFile(self::DIR.'/config/packages/echo.yaml')['echo']);

        self::assertSame('immediate', $config['events']['signup']['mode']);
        self::assertTrue($config['events']['page_view']['skip_bots']);
    }

    /** recipes-contrib's CI refuses any other indentation. */
    public function testTheYamlIsIndentedByMultiplesOfFourSpaces(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DIR, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with((string) $file, '.yaml')) {
                continue;
            }
            foreach (file((string) $file) as $i => $line) {
                $code = ltrim($line, ' ');
                if ('' === trim($line)) {
                    continue;
                }
                self::assertStringNotContainsString("\t", $line, $file.':'.($i + 1));
                $indent = \strlen($line) - \strlen($code);
                // A commented-out block keeps the indentation after its "#".
                if (str_starts_with($code, '#') && preg_match('/^# ( *)\S/', $code, $m) && '' !== $m[1]) {
                    $indent = \strlen($m[1]);
                }
                self::assertSame(0, $indent % 4, $file.':'.($i + 1).': '.$line);
                self::assertDoesNotMatchRegularExpression('/^\s*- \w+:/', $line, 'a list item goes as "-" on its own line: '.$file.':'.($i + 1));
            }
        }
    }

    public function testTheManifestRegistersTheBundleCopiesTheConfigAndAddsTheEnv(): void
    {
        $manifest = json_decode(file_get_contents(self::DIR.'/manifest.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(['all'], $manifest['bundles']['Acencyril\\EchoBundle\\EchoBundle']);
        self::assertSame('%CONFIG_DIR%/', $manifest['copy-from-recipe']['config/']);
        self::assertArrayHasKey('ECHO_OWNER', $manifest['env']);
        self::assertArrayHasKey('ECHO_SENDER', $manifest['env']);
    }

    public function testTheReadmeExamplesAreValid(): void
    {
        foreach (['README.md', 'README.fr.md'] as $file) {
            preg_match_all('/```yaml\n((?:#[^\n]*\n)*echo:\n.*?)```/s', file_get_contents(__DIR__.'/../'.$file), $m);
            $blocks = array_filter($m[1], static fn (string $b) => str_contains($b, '    owner:'));
            self::assertNotEmpty($blocks, $file);
            foreach ($blocks as $block) {
                ConfigurationTest::process(Yaml::parse($block)['echo']);
                $this->addToAssertionCount(1);
            }
        }
    }
}

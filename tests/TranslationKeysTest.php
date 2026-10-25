<?php

declare(strict_types=1);

namespace Acencyril\EchoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class TranslationKeysTest extends TestCase
{
    private static function keys(array $tree, string $prefix = ''): array
    {
        $out = [];
        foreach ($tree as $k => $v) {
            $out = array_merge($out, \is_array($v) ? self::keys($v, $prefix.$k.'.') : [$prefix.$k => $v]);
        }

        return $out;
    }

    public function testFrenchAndEnglishHaveTheSameKeysAndPlaceholders(): void
    {
        $en = self::keys(Yaml::parseFile(__DIR__.'/../translations/echo.en.yaml'));
        $fr = self::keys(Yaml::parseFile(__DIR__.'/../translations/echo.fr.yaml'));

        self::assertSame(array_keys($en), array_keys($fr));
        foreach ($en as $key => $text) {
            preg_match_all('/%\w+%/', (string) $text, $a);
            preg_match_all('/%\w+%/', (string) $fr[$key], $b);
            sort($a[0]);
            sort($b[0]);
            self::assertSame($a[0], $b[0], $key);
        }
    }

    public function testEveryKeyUsedInTheCodeExists(): void
    {
        $en = self::keys(Yaml::parseFile(__DIR__.'/../translations/echo.en.yaml'));
        preg_match_all("/'(echo\\.(?:mail|digest|yes|no)[a-z_.]*)'/", file_get_contents(__DIR__.'/../src/Postman.php'), $m);

        self::assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $key) {
            self::assertArrayHasKey($key, $en, $key);
        }
    }
}

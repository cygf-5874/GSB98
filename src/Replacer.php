<?php

declare(strict_types=1);

namespace Replsafe;

/**
 * 正则替换封装：把 preg_* 上的替换语义收敛成几条确定、可复用的接口。
 *
 * 只依赖 PHP 标准库（php-cli）。对外契约见 README「对外契约」一节。
 */
final class Replacer
{
    /**
     * 全局替换一次，返回替换后的字符串。
     */
    public static function replace(
        ?string $subject,
        string $pattern,
        string $replacement,
        bool $expandRefs = false,
        bool $utf8Mode = false
    ): string {
        return self::replaceAll($subject, $pattern, $replacement, $expandRefs, $utf8Mode)[0];
    }

    /**
     * 全局替换，返回 [$result, $count]。
     *
     * @return array{0: string, 1: int}
     */
    public static function replaceAll(
        ?string $subject,
        string $pattern,
        string $replacement,
        bool $expandRefs = false,
        bool $utf8Mode = false
    ): array {
        $effective = self::effectivePattern($pattern, $utf8Mode);

        if ($subject === null) {
            $subject = '';
        }

        if ($expandRefs) {
            Meter::count(strlen($subject));

            $all = [];
            @preg_match_all($effective, $subject, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            $result = '';
            $cursor = 0;
            $count = 0;

            foreach ($all as $match) {
                $text = (string) $match[0][0];
                $start = (int) $match[0][1];

                $result .= self::segment($subject, $cursor, $start);
                $result .= self::expand($replacement, $match);

                $cursor = $start + strlen($text);
                $count++;
            }

            $result .= self::segment($subject, $cursor, strlen($subject));

            return [$result, $count];
        }

        $count = 0;
        $result = @preg_replace($effective, $replacement, $subject, -1, $count);

        return [(string) $result, (int) $count];
    }

    /**
     * 按 $pattern 切分 $subject；$pattern 里的捕获组会按编号插在片段之间。
     *
     * @return string[]
     */
    public static function split(string $subject, string $pattern, int $limit = -1): array
    {
        $pieces = preg_split($pattern, $subject, $limit);

        if ($pieces === false) {
            $pieces = [$subject];
        }

        $all = [];
        preg_match_all($pattern, $subject, $all, PREG_SET_ORDER);

        $out = [];
        foreach ($all as $i => $match) {
            $out[] = $pieces[$i] ?? '';

            foreach (array_slice($match, 1) as $group) {
                $out[] = (string) $group;
            }
        }

        $out[] = $pieces[count($all)] ?? '';

        return $out;
    }

    /**
     * 取主体上 [from, to) 这一段文本。
     */
    private static function segment(string $subject, int $from, int $to): string
    {
        if ($to <= $from) {
            return '';
        }

        $head = substr($subject, 0, $to);
        Meter::count($to);

        return substr($head, $from);
    }

    /**
     * 把调用方给的 pattern 变成最终交给 preg_* 的 pattern。
     */
    private static function effectivePattern(string $pattern, bool $utf8Mode): string
    {
        return $pattern . 'u';
    }

    /**
     * 展开 $replacement 里的引用。
     *
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function expand(string $replacement, array $match): string
    {
        $out = preg_replace_callback(
            '/\$(\d+)/',
            static function (array $groups) use ($match): string {
                return self::groupText($match, (int) $groups[1]);
            },
            $replacement
        );

        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $groups) use ($match): string {
                return self::groupText($match, $groups[1]);
            },
            (string) $out
        );
    }

    /**
     * 取某个捕获组的文本（$match 是带偏移的匹配结果）。
     *
     * @param array<int|string, array{0: string, 1: int}> $match
     */
    private static function groupText(array $match, int|string $index): string
    {
        if (!array_key_exists($index, $match)) {
            return '';
        }

        return (string) $match[$index][0];
    }
}

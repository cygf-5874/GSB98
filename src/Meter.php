<?php

declare(strict_types=1);

namespace Replsafe;

/**
 * 单遍预算的计数器（README「对外契约」第 9 条）。
 *
 * **固定入口：字段名与语义都别改。** 固定件在调用 `Replacer::replaceAll()` 之前先
 * `Meter::reset()`，调用之后读 `Meter::$subjectBytes`。
 *
 *   $subjectBytes —— 实现访问过的**主体字节数**的累计值。实现每读取主体的一段连续字节，
 *                    就把该段长度交给自己累加（用 `Meter::count()` 即可）。
 *                   固定件要求它 `< 6 × strlen($subject)`，并且 `>= strlen($subject)`
 *                   以便区分「真的扫过主体」与「压根没计数」。
 *
 * 固定件只读不写。
 */
final class Meter
{
    /** 主体字节访问次数（累计）。 */
    public static int $subjectBytes = 0;

    /** 固定件在每次测量前调用，把计数器清零。 */
    public static function reset(): void
    {
        self::$subjectBytes = 0;
    }

    /** 实现报告一段主体字节读取；非正数不计。 */
    public static function count(int $bytes): void
    {
        if ($bytes > 0) {
            self::$subjectBytes += $bytes;
        }
    }
}

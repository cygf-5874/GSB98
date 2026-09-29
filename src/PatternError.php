<?php

declare(strict_types=1);

namespace Replsafe;

/**
 * PCRE 层错误：pattern 编译失败，或运行期字符编码错误。
 *
 * 构造时固定携带「原始 PCRE 错误文本」与「PCRE 错误码」，便于调用方直接定位。
 */
final class PatternError extends \RuntimeException
{
    /**
     * @param string $pattern     触发错误的原始 pattern
     * @param string $pcreMessage 原始 PCRE 错误文本（如 Compilation failed: ... at offset N）
     * @param int    $pcreCode    PCRE 错误码（preg_last_error() 的整数）
     */
    public function __construct(string $pattern, string $pcreMessage, int $pcreCode)
    {
        parent::__construct(
            sprintf('pattern %s 非法：%s（PCRE 错误码 %d）', $pattern, $pcreMessage, $pcreCode),
            $pcreCode
        );
    }
}

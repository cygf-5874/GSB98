<?php

declare(strict_types=1);

/**
 * 调用方给的最小复现。
 *
 * 跑法：php repro.php —— 两处现象都不再复现才退 0，否则退 1。
 */

require __DIR__ . '/autoload.php';

use Replsafe\Replacer;

$broken = false;

// ---------------------------------------------------------------------------
// 现象一：模板里填进去的金额替换串，输出不是填进去的那串文本
// ---------------------------------------------------------------------------

$template = '金额：123 元';
$filled = '$1';
$want1 = '金额：$1 元';
$got1 = Replacer::replace($template, '/(\d+)/', $filled);

printf(
    "① 替换串 %s：\n   期望 %s\n   实际 %s\n",
    var_export($filled, true),
    var_export($want1, true),
    var_export($got1, true)
);

if ($got1 !== $want1) {
    $broken = true;
}

// ---------------------------------------------------------------------------
// 现象二：带二进制字节的主体，替换后整段输出没了
// ---------------------------------------------------------------------------

$binary = "A\x00\xFFB";
$want2 = "A-\xFFB";
$got2 = Replacer::replace($binary, '/\x00/', '-');

printf(
    "② 二进制主体：\n   期望 %s\n   实际 %s\n",
    bin2hex($want2),
    bin2hex($got2)
);

if ($got2 !== $want2) {
    $broken = true;
}

exit($broken ? 1 : 0);

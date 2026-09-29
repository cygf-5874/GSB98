<?php

declare(strict_types=1);

/**
 * replsafe 回归用例：18 个。
 *
 * 覆盖既有能力：替换串里不含 `$` 与 `\`，主体都是合法 UTF-8 文本或 ASCII。
 * 跑法：php tests/run.php —— 全过打印「通过 18/18」并退 0，否则退 1。
 */

require __DIR__ . '/../autoload.php';

use Replsafe\Replacer;

$cases = [];

$cases[] = ['简单字面替换', Replacer::replace('hello world', '/o/', '0'), 'hell0 w0rld'];
$cases[] = ['无匹配时原样返回', Replacer::replace('hello', '/z/', 'X'), 'hello'];
$cases[] = ['转义点号', Replacer::replace('a.b.c', '/\./', '-'), 'a-b-c'];
$cases[] = ['replaceAll 结果', Replacer::replaceAll('a.b.c', '/\./', '-')[0], 'a-b-c'];
$cases[] = ['replaceAll 计数', Replacer::replaceAll('a.b.c', '/\./', '-')[1], 2];
$cases[] = ['零次替换计数为 0', Replacer::replaceAll('abc', '/z/', '-')[1], 0];
$cases[] = ['空主体', Replacer::replace('', '/a/', 'x'), ''];
$cases[] = ['替换为空串（删除）', Replacer::replace('aaa', '/a/', ''), ''];
$cases[] = ['替换串含数字', Replacer::replace('n', '/n/', '2024'), '2024'];
$cases[] = ['替换串为整段文本', Replacer::replace('x', '/x/', 'abc'), 'abc'];
$cases[] = ['分支模式', Replacer::replace('cat', '/cat|dog/', 'pet'), 'pet'];
$cases[] = ['默认区分大小写', Replacer::replace('Cat', '/cat/', 'pet'), 'Cat'];
$cases[] = ['井号定界符', Replacer::replace('a-b', '#-#', '_'), 'a_b'];
$cases[] = ['多行主体', Replacer::replace("l1\nl2", '/l/', 'L'), "L1\nL2"];
$cases[] = ['多次替换', Replacer::replace('o o o', '/o/', '0'), '0 0 0'];
$cases[] = ['UTF-8 主体按字节匹配', Replacer::replace('你好', '/好/', 'x'), '你x'];
$cases[] = ['split 基本切分', Replacer::split('a,b,c', '/,/'), ['a', 'b', 'c']];
$cases[] = ['split 无匹配', Replacer::split('abc', '/,/'), ['abc']];

$passed = 0;
$failed = 0;

foreach ($cases as [$name, $got, $want]) {
    if ($got === $want) {
        $passed++;
        continue;
    }

    $failed++;
    printf("FAIL %s\n  期望=%s\n  实际=%s\n", $name, var_export($want, true), var_export($got, true));
}

$total = count($cases);
printf("通过 %d/%d\n", $passed, $total);

exit($failed === 0 ? 0 : 1);

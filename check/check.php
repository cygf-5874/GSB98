<?php

declare(strict_types=1);

/**
 * 固定验收入口：replsafe 的「对外保证」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                      跑全部 10 个场景，全过才退 0
 *   php check/check.php -list                列出全部场景
 *   php check/check.php --only literal       只跑一组
 *   php check/check.php --only literal,expand  只跑指定的几组
 *
 * 五组共 10 个场景：
 *   literal 3 —— 默认路径下 `$` / `\` / `$0` 都按字面输出；
 *   expand  3 —— `$1` 与 `\1` 一致；不存在的组展开为空串；`${name}` 命名组；
 *   errors  2 —— null 主体抛 TypeError；非法 pattern 抛 PatternError 且带原始 PCRE 文本与错误码；
 *   consist 1 —— split 与 replaceAll 的组语义一致（含具名组、未参与组、limit）；
 *   budget  1 —— replaceAll 对主体的字节访问量 < 6 × strlen(subject)。
 *
 * 只依赖 PHP 8 标准库。判据完全确定：不读时间、不用随机源、不依赖 ini、
 * 不依赖 PHP 数组 hash 顺序。失败不早退；每个场景用 try/catch(\Throwable) 兜住后继续。
 */

require __DIR__ . '/../autoload.php';

use Replsafe\Meter;
use Replsafe\PatternError;
use Replsafe\Replacer;

// ---------------------------------------------------------------------------
// 断言失败
// ---------------------------------------------------------------------------

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

/** 逐字节比较；失败时把期望 / 实际都写成可读的十六进制。 */
function expect_same(string $want, string $got, string $what): void
{
    if ($want === $got) {
        return;
    }

    throw new CheckFailure(
        $what,
        var_export($want, true) . ' (hex ' . bin2hex($want) . ')',
        var_export($got, true) . ' (hex ' . bin2hex($got) . ')'
    );
}

function expect_true(bool $cond, string $what, string $expected = 'true', string $actual = 'false'): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, $actual);
    }
}

function show_list(array $v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '(list)';
}

function expect_list(array $want, array $got, string $what): void
{
    if ($want === $got) {
        return;
    }

    throw new CheckFailure($what, show_list($want), show_list($got));
}

// ---------------------------------------------------------------------------
// [literal] 3 —— 默认路径：$、\ 一律字面
// ---------------------------------------------------------------------------

$literal = [
    tk('dollar', '默认路径里替换串的 $ 原样输出', function (): void {
        $got = Replacer::replace('price 42', '/(\d+)/', '$1');
        expect_same('price $1', $got, '替换串里的 $1 必须字面输出，不得当反向引用');

        $got = Replacer::replace('n=7', '/(\d+)/', '$1.00 $');
        expect_same('n=$1.00 $', $got, '替换串里的每个 $ 都必须字面输出');
    }),

    tk('backslash', '默认路径里替换串的 \ 原样输出', function (): void {
        $got = Replacer::replace('price 42', '/(\d+)/', 'C:\snap\1');
        expect_same('price C:\snap\1', $got, '替换串里的 \ 必须字面输出，不得当转义或反向引用');
    }),

    tk('dollar0', '默认路径里 $0 也按字面，不当整段匹配', function (): void {
        $got = Replacer::replace('price 42', '/(\d+)/', '[$0]');
        expect_same('price [$0]', $got, '$0 必须字面输出');
    }),
];

// ---------------------------------------------------------------------------
// [expand] 3 —— 显式展开
// ---------------------------------------------------------------------------

$expand = [
    tk('parity', '$1 与 \1 对同一个组给出一致结果', function (): void {
        $dollar = Replacer::replace('price 42', '/(\d+)/', '<$1>', true);
        expect_same('price <42>', $dollar, '$1 应展开为捕获组文本');

        $backslash = Replacer::replace('price 42', '/(\d+)/', '<\1>', true);
        expect_same('price <42>', $backslash, '\1 应展开为同一个捕获组文本');
    }),

    tk('missing', '不存在的组展开为空串', function (): void {
        $got = Replacer::replace('price 42', '/(\d+)/', '[$1][$3]', true);
        expect_same('price [42][]', $got, '不存在的第 3 组应展开为空串，而不是原文');
    }),

    tk('named', '${name} 取命名捕获组', function (): void {
        $got = Replacer::replace('ab12', '/(?<w>[a-z]+)(?<n>\d+)/', '${w}->${n}', true);
        expect_same('ab->12', $got, '${name} 应取对应的命名捕获组文本');
    }),
];

// ---------------------------------------------------------------------------
// [errors] 2 —— null 主体 / 非法 pattern
// ---------------------------------------------------------------------------

$errors = [
    tk('nulltype', 'null 主体抛 TypeError', function (): void {
        $threw = false;

        try {
            Replacer::replace(null, '/a/', 'b');
        } catch (\TypeError $e) {
            $threw = true;
        }

        expect_true($threw, 'null 主体必须抛 TypeError，不得静默当空串', 'TypeError', '正常返回');
    }),

    tk('badpattern', '非法 pattern 抛 PatternError，带原始 PCRE 文本与错误码', function (): void {
        // 独立探针：拿到原始 PCRE 错误文本与错误码，作为断言基准。
        $probeMessage = null;
        set_error_handler(static function (int $severity, string $message) use (&$probeMessage): bool {
            if ($probeMessage === null) {
                $probeMessage = $message;
            }

            return true;
        });
        $probeResult = preg_match('/[/', 'x');
        restore_error_handler();
        $probeCode = preg_last_error();

        expect_true(
            $probeResult === false && $probeCode !== 0 && $probeMessage !== null,
            '探针 pattern /[/ 应当编译失败',
            'false + 非 0 错误码',
            var_export($probeResult, true)
        );

        $threw = false;

        try {
            Replacer::replace('x', '/[/', 'y');
        } catch (PatternError $e) {
            $threw = true;
            $message = $e->getMessage();

            expect_true(
                str_contains($message, 'Compilation failed'),
                '消息里必须带上原始 PCRE 错误文本',
                '含 "Compilation failed"',
                $message
            );
            expect_true(
                str_contains($message, '错误码 ' . $probeCode),
                '消息里必须带上 PCRE 错误码',
                '含 "错误码 ' . $probeCode . '"',
                $message
            );
        }

        expect_true($threw, '非法 pattern 必须抛 PatternError', 'PatternError', '正常返回');
    }),
];

// ---------------------------------------------------------------------------
// [consist] 1 —— split 与 replaceAll 的组语义一致
// ---------------------------------------------------------------------------

$consist = [
    tk('groups', 'split 的组编号 / 空组 / limit 与 replaceAll 一致', function (): void {
        $subject = 'a, b;c';
        $pattern = '/(?<pre> ?)(?<sep>[,;])/';

        // 独立基准：用原生 preg_* 自己算一遍，不依赖被测实现。
        $raw = [];
        preg_match_all($pattern, $subject, $raw, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $cursor = 0;
        $wantExpanded = '';
        $wantSplit = [];
        foreach ($raw as $m) {
            $start = $m[0][1];
            $text = $m[0][0];

            $wantSplit[] = substr($subject, $cursor, $start - $cursor);
            $wantExpanded .= substr($subject, $cursor, $start - $cursor);

            $g = [];
            for ($i = 1; array_key_exists($i, $m); $i++) {
                $g[$i] = $m[$i][0];
                $wantSplit[] = $m[$i][0];
            }

            $named = array_key_exists('sep', $m) ? $m['sep'][0] : '';
            $wantExpanded .= '<' . $g[1] . '|' . $g[2] . '|' . $named . '>';

            $cursor = $start + strlen($text);
        }

        $wantSplit[] = substr($subject, $cursor);
        $wantExpanded .= substr($subject, $cursor);

        // (1) replaceAll 的展开必须与基准一致（具名组 + 空组）。
        expect_same($wantExpanded, Replacer::replace($subject, $pattern, '<$1|$2|${sep}>', true), 'replaceAll 展开结果');

        // (2) split 的组语义必须与 replaceAll 用同一套编号：逐槽位相同。
        expect_list($wantSplit, Replacer::split($subject, $pattern), 'split 的片段与组文本');

        // (3) limit：limit < 1 不限制；limit >= 1 时最多 limit 个片段，其余原样留在最后一段。
        expect_list(['a', '', ',', ' b;c'], Replacer::split($subject, $pattern, 2), 'split(limit=2)');
        expect_list(['a', 'b,c'], Replacer::split('a,b,c', '/,/', 2), 'split(limit=2) 无捕获组');
        expect_list(['a,b,c'], Replacer::split('a,b,c', '/,/', 1), 'split(limit=1) 不切分');
        expect_list(['a', 'b', 'c'], Replacer::split('a,b,c', '/,/', 0), 'split(limit=0) 不限制');
    }),
];

// ---------------------------------------------------------------------------
// [budget] 1 —— 单遍预算
// ---------------------------------------------------------------------------

$budget = [
    tk('single-pass', 'replaceAll 不得对每个匹配重扫主体', function (): void {
        $subject = str_repeat('a1b', 400); // 1200 字节、400 个匹配
        $n = strlen($subject);

        expect_true($n === 1200, '预算场景主体长度应为 1200', '1200', (string) $n);

        Meter::reset();
        $result = Replacer::replaceAll($subject, '/\d/', '-', true);
        $bytes = Meter::$subjectBytes;

        expect_same(str_repeat('a-b', 400), $result[0], '预算场景的替换结果');
        expect_true($result[1] === 400, '预算场景的替换次数应为 400', '400', (string) $result[1]);

        expect_true(
            $bytes >= $n,
            'Meter::$subjectBytes 必须真实反映主体至少被读过一遍',
            '>= ' . $n,
            (string) $bytes
        );

        expect_true(
            $bytes < 6 * $n,
            '主体字节访问次数必须 < 6 × strlen(subject)',
            '< ' . (6 * $n),
            (string) $bytes
        );
    }),
];

$GROUPS = [
    'literal' => $literal,
    'expand' => $expand,
    'errors' => $errors,
    'consist' => $consist,
    'budget' => $budget,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（literal/expand/errors/consist/budget）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-12s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);

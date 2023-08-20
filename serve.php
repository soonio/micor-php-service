<?php
declare(strict_types=1);
date_default_timezone_set('PRC');

use Colors\Color;
use Commando\Command;
use Shiren\TAM\A;
use Shiren\Te\Ee\Energy;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Process;

require __DIR__ . "/vendor/autoload.php";

$verbose = false; // 控制是否显示日志


function Verbose(string $info)
{
    global $verbose;
    if ($verbose) {
        echo date('Y-m-d H:i:s') . ' ' . $info . PHP_EOL;
    }
}

// 显示版本
function Version(): string
{
    $bt = date('Y-m-d H:i:s', strtotime('@build_datetime@') + 8 * 60 * 60);
    return (new Color())('V@version@')->green()->bold() . '@@git_commit_short@ buildin ' . $bt;
}

// 消息结构体
function message(array $data, int $code = 0, string $message = 'success')
{
    return json_encode([
        'code' => $code,
        'data' => $data,
        'msg' => $message,
    ]);
}

// 解析CID
function parseCID(string $cid): array
{
    $cs = str_split($cid);
    return array_map(function (string $i): int {
        switch ($i) {
            case "A":
                return 10;
            case "B":
                return 11;
            default:
                return (int)$i;
        }
    }, $cs);
}

/**
 * 计算大运能量比
 * @param array $cs
 * @param bool $inTurn
 * @param int $size
 * @return array
 */
function Calc(array $cs, bool $inTurn, int $size): array
{
    $rates = [];
    $g = $cs[1];
    $z = $cs[5];
    for ($i = 0; $i < $size; $i++) {
        if ($inTurn) {
            $g = A::nextG($g);
            $z = A::nextZ($z);
        } else {
            $g = A::PrevG($g);
            $z = A::PrevZ($z);
        }
        $es = Energy::createFromGZ([$cs[0], $cs[1], $cs[2], $cs[3], $g], [$cs[4], $cs[5], $cs[6], $cs[7], $z])->calculate()->values();
        $ts = types($es, $cs[2]);
        $total = array_reduce($ts, function ($a, $b) {
            return $a + $b;
        }, 0);
        $strong = $es[0] + $es[1]; // 计算命强的能量(我的能量)
        $rate = (int)(round($strong / $total, 2) * 100);
        $rates[] = $rate;
    }
    return $rates;
}

/**
 * 计算原命盘能量比
 * @param array $cs
 * @return int
 */
function Origin(array $cs): int
{
    $es = Energy::createFromGZ([$cs[0], $cs[1], $cs[2], $cs[3]], [$cs[4], $cs[5], $cs[6], $cs[7]])->calculate()->values();
    $ts = types($es, $cs[2]);
    $total = array_reduce($ts, function ($a, $b) {
        return $a + $b;
    }, 0);
    $strong = $es[0] + $es[1]; // 计算命强的能量(我的能量)
    return (int)(round($strong / $total, 2) * 100);
}

function types(array $es, $dg): array
{
    $dge = A::g2e($dg);
    $ts = [];
    foreach ($es as $e => $v) { // 五行能量转五神能量
        $ts[A::spirit($dge, $e)] = $v;
    }
    return $ts;
}

$cli = new Command();

$cli->option('h')->aka("host")->describe("host for serve.")->default('0.0.0.0');
$cli->option('p')->aka("port")->describe("port for serve.")->default(8101);
$cli->option('d')->aka("daemonize")->describe("serve daemonize.")->default(false)->boolean();
$cli->option('v')->aka("verbose")->describe("show detail info.")->default(false)->boolean();

$host = $cli['host'];
$port = (int)$cli['port'];
$daemonize = $cli['daemonize'];
$verbose = $cli['verbose'];
unset($cli);// 释放变量

$dc = (new Color())($daemonize ? 'On' : 'Off');

echo sprintf("Version:\t%s\nServeOn:\t%s://%s:%d\nDaemonize:\t%s\nStartAt:\t%s\n",
    Version(),
    "http",
    $host,
    $port,
    $daemonize ? $dc->green() : $dc->red(),
    date('Y-m-d H:i:s')
);

Swoole\Coroutine\run(function () use ($host, $port, $daemonize, $verbose) {
    $http = new Server($host, $port);

    $http->set([
        'daemonize' => $daemonize
    ]);

    Process::signal(SIGTERM, function () use ($http) {
        $http->shutdown();
    });
    Process::signal(SIGINT, function () use ($http) {
        $http->shutdown();
    }); // 在命令行模式下，支持Ctrl+C关闭

    $http->handle('/', function (Request $request, Response $response) {
        Swoole\Coroutine::create(function () use ($request, $response) {
            $content = $request->getContent();
            Verbose($content);
            if ("" != $content) {
                $data = json_decode($content, true);
                if (isset($data['cid']) && strlen($data['cid']) == 8 &&
                    isset($data['inturn']) && is_bool($data['inturn']) &&
                    isset($data['size']) && 0 < $data['size'] && $data['size'] < 16) {
                    $cs = parseCID($data['cid']);
                    if (count($cs) == 8) {
                        try {
                            $rate = Calc($cs, $data['inturn'] == 1, $data['size']);
                            $origin = Origin($cs);
                            $ret = message(compact('rate', 'origin'));
                        } catch (Exception $e) {
                            $ret = message([], 500, $e->getMessage());
                        }
                    } else {
                        $ret = message([], 422, "参数格式错误.1");
                    }
                } else {
                    $ret = message([], 422, "参数格式错误.0");
                }
            } else {
                $ret = message([], 1, "message format error.");
            }

            Verbose($ret);

            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end($ret);
            return 0;
        });
    });

    $http->start();

});

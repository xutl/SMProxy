<?php

namespace SMProxy;

use SMProxy\Handler\Frontend\FrontendAuthenticator;
use SMProxy\Handler\Frontend\FrontendConnection;
use function SMProxy\Helper\getString;
use function SMProxy\Helper\initConfig;
use function SMProxy\Helper\packageSplit;
use SMProxy\Helper\ProcessHelper;
use SMProxy\Log\Log;
use SMProxy\MysqlPacket\AuthPacket;
use SMProxy\MysqlPacket\MySqlPacketDecoder;
use SMProxy\MysqlPacket\MySQLPacket;
use SMProxy\MysqlPacket\OkPacket;
use SMProxy\MysqlPacket\Util\ErrorCode;
use SMProxy\MysqlPool\MySQLException;
use SMProxy\MysqlPool\MySQLPool;
use SMProxy\Parser\ServerParse;
use Swoole\Coroutine;

/**
 * Author: Louis Livi <574747417@qq.com>
 * Date: 2018/10/26
 * Time: 下午6:32.
 */
class SMProxyServer extends BaseServer
{
    public $source;
    public $mysqlClient;
    protected $dbConfig;

    /**
     * 连接.
     *
     * @param $server
     * @param $fd
     */
    public function onConnect(\swoole_server $server, int $fd)
    {
        // 生成认证数据
        $Authenticator = new FrontendAuthenticator();
        $this->source[$fd] = $Authenticator;
        if ($server->exist($fd)) {
            $server->send($fd, $Authenticator->getHandshakePacket($fd));
        }
    }

    /**
     * 接收消息.
     *
     * @param $server
     * @param $fd
     * @param $reactor_id
     * @param $data
     */
    public function onReceive(\swoole_server $server, int $fd, int $reactor_id, string $data)
    {
        self::go(function () use ($server, $fd, $reactor_id, $data) {
            if (!isset($this->source[$fd]->auth)) {
                throw new SMProxyException('Must be connected before sending data!');
            }
            $packages = packageSplit($data, $this->source[$fd]->auth ?: false);
            foreach ($packages as $package) {
                $data = $package;
                self::go(function () use ($server, $fd, $reactor_id, $data) {
                    $bin = (new MySqlPacketDecoder())->decode($data);
                    if (!$this->source[$fd]->auth) {
                        $authPacket = new AuthPacket();
                        $authPacket->read($bin);
                        $checkPassword = $this->source[$fd]
                            ->checkPassword($authPacket->password, CONFIG['server']['password']);
                        if (CONFIG['server']['user'] != $authPacket->user || !$checkPassword) {
                            $message = 'SMProxy access denied for user \'' . $authPacket->user . '\'@\'' .
                                $server ->getClientInfo($fd)['remote_ip'] . '\' (using password: YES)';
                            $errMessage = $this->writeErrMessage(2, $message, ErrorCode::ER_ACCESS_DENIED_ERROR, 28000);
                            if ($server->exist($fd)) {
                                $server->send($fd, getString($errMessage));
                            }
                            throw new MySQLException($message);
                        } else {
                            if ($server->exist($fd)) {
                                $server->send($fd, getString(OkPacket::$AUTH_OK));
                            }
                            $this->source[$fd]->auth = true;
                            $this->source[$fd]->database = $authPacket->database;
                        }
                    } else {
                        $trim_data = rtrim($data);
                        switch ($bin->data[4]) {
                            case MySQLPacket::$COM_INIT_DB:
                                // just init the frontend
                                break;
                            case MySQLPacket::$COM_QUERY:
                            case MySQLPacket::$COM_STMT_PREPARE:
                                $connection = new FrontendConnection();
                                $queryType = $connection->query($bin);
                                if (ServerParse::SELECT == $queryType ||
                                    ServerParse::SHOW == $queryType ||
                                    (ServerParse::SET == $queryType && false === strpos($data, 'autocommit', 4)) ||
                                    ServerParse::USE == $queryType
                                ) {
                                    //处理读操作
                                    if (!isset($this->connectHasTransaction[$fd]) ||
                                        !$this->connectHasTransaction[$fd]) {
                                        if ((('u' == $trim_data[-6] || 'U' == $trim_data[-6]) &&
                                            ServerParse::UPDATE == ServerParse::uCheck($trim_data, -6, false))) {
                                            //判断悲观锁
                                            $this->connectReadState[$fd] = false;
                                        } else {
                                            $this->connectReadState[$fd] = true;
                                        }
                                    }
                                } elseif (ServerParse::START == $queryType || ServerParse::BEGIN == $queryType
                                ) {
                                    //处理事务
                                    $this->connectHasTransaction[$fd] = true;
                                    $this->connectReadState[$fd] = false;
                                } elseif (ServerParse::SET == $queryType && false !== strpos($data, 'autocommit', 4) &&
                                    0 == $trim_data[-1]) {
                                    //处理autocommit事务
                                    $this->connectHasAutoCommit[$fd] = true;
                                    $this->connectHasTransaction[$fd] = true;
                                    $this->connectReadState[$fd] = false;
                                } elseif (ServerParse::SET == $queryType && false !== strpos($data, 'autocommit', 4) &&
                                    1 == $trim_data[-1]) {
                                    $this->connectHasAutoCommit[$fd] = false;
                                    $this->connectReadState[$fd] = false;
                                } elseif (ServerParse::COMMIT == $queryType || ServerParse::ROLLBACK == $queryType) {
                                    //事务提交
                                    $this->connectHasTransaction[$fd] = false;
                                } else {
                                    $this->connectReadState[$fd] = false;
                                }
                                break;
                            case MySQLPacket::$COM_PING:
                                break;
                            case MySQLPacket::$COM_QUIT:
                                //禁用客户端退出
                                $data = '';
                                break;
                            case MySQLPacket::$COM_PROCESS_KILL:
                                break;
                            case MySQLPacket::$COM_STMT_EXECUTE:
                                break;
                            case MySQLPacket::$COM_STMT_CLOSE:
                                break;
                            case MySQLPacket::$COM_HEARTBEAT:
                                break;
                            default:
                                break;
                        }
                        if (isset($this->connectReadState[$fd]) && true === $this->connectReadState[$fd]) {
                            $model = 'read';
                            $key = $this->source[$fd]->database ? $model . DB_DELIMITER . $this->source[$fd]->database : $model;
                            //如果没有读库 默认用写库
                            if (!array_key_exists($key, $this->dbConfig)) {
                                $model = 'write';
                            }
                        } else {
                            $model = 'write';
                        }
                        if (isset($this->mysqlClient[$fd][$model])) {
                            $client = $this->mysqlClient[$fd][$model];
                            if ($data && $client->client->isConnected()) {
                                $client->client->send($data);
                            }
                        } else {
                            $key = $this->source[$fd]->database ? $model . DB_DELIMITER . $this->source[$fd]->database : $model;
                            if (array_key_exists($key, $this->dbConfig)) {
                                $client = MySQLPool::fetch($key, $server, $fd);
                                $this->mysqlClient[$fd][$model] = $client;
                                if ($data && $client->client->isConnected()) {
                                    $client->client->send($data);
                                }
                            } else {
                                $message = 'Database config ' . ($this->source[$fd]->database ?: '') . ' ' . $model .
                                    ' is not exists!';
                                $errMessage = $this->writeErrMessage(1, $message, ErrorCode::ER_SYNTAX_ERROR, 42000);
                                if ($server->exist($fd)) {
                                    $server->send($fd, getString($errMessage));
                                }
                                throw new MySQLException($message);
                            }
                        }
                    }
                });
            }
        });
    }

    /**
     * 客户端断开连接.
     *
     * @param \swoole_server $server
     * @param int            $fd
     *
     */
    public function onClose(\swoole_server $server, int $fd)
    {
        if (isset($this->source[$fd])) {
            unset($this->source[$fd]);
        }
        $connectHasTransaction = false;
        $connectHasAutoCommit  = false;
        if (isset($this->connectHasTransaction[$fd]) && true === $this->connectHasTransaction[$fd]) {
            //回滚未关闭事务
            $connectHasTransaction = true;
            unset($this->connectHasTransaction[$fd]);
        }
        if (isset($this->connectHasAutoCommit[$fd]) && true === $this->connectHasAutoCommit[$fd]) {
            //开启autocommit=0未关闭
            $connectHasAutoCommit = true;
            unset($this->connectHasAutoCommit[$fd]);
        }
        if (isset($this->mysqlClient[$fd])) {
            if ($this->mysqlClient[$fd]['write'] ->client && $this->mysqlClient[$fd]['write'] ->client->isConnected()) {
                if ($connectHasTransaction) {
                    $this->mysqlClient[$fd]['write']->client->send(getString([9, 0, 0, 0, 3, 82, 79, 76, 76, 66, 65, 67, 75]));
                }
                if ($connectHasAutoCommit) {
                    $this->mysqlClient[$fd]['write']->client->send(getString([
                        17, 0, 0, 0, 3, 115, 101, 116, 32, 97, 117, 116, 111, 99, 111, 109, 109, 105, 116, 61, 49,
                    ]));
                }
            }
            foreach ($this->mysqlClient[$fd] as $mysqlClient) {
                MySQLPool::recycle($mysqlClient);
            }
            unset($this->mysqlClient[$fd]);
        }
        if (isset($this->connectReadState[$fd])) {
            unset($this->connectReadState[$fd]);
        }
        parent::onClose($server, $fd);
    }

    /**
     * WorkerStart.
     *
     * @param \swoole_server $server
     * @param int $worker_id
     */
    public function onWorkerStart(\swoole_server $server, int $worker_id)
    {
        self::go(function () use ($server, $worker_id) {
            if ($worker_id >= CONFIG['server']['swoole']['worker_num']) {
                ProcessHelper::setProcessTitle('SMProxy task process');
            } else {
                ProcessHelper::setProcessTitle('SMProxy worker process');
            }
            $this->dbConfig = $this->parseDbConfig(initConfig(CONFIG_PATH));
            //初始化链接
            MySQLPool::init($this->dbConfig);
            if ($worker_id === (CONFIG['server']['swoole']['worker_num'] - 1)) {
                try {
                    Coroutine::sleep(0.1);
                    $this ->setStartConns();
                } catch (MySQLException $exception) {
                    $server ->shutdown();
                    echo 'ERROR:' . $exception ->getMessage(), PHP_EOL;
                    return;
                }
                $system_log = Log::getLogger('system');
                $system_log->info('Worker started!');
                echo 'Worker started!', PHP_EOL;
            }
        });
    }

    /**
     * 设置服务启动连接数
     *
     * @throws MySQLException
     */
    private function setStartConns()
    {
        $clients = [];
        foreach ($this->dbConfig as $key => $value) {
            if (count(explode(DB_DELIMITER, $key)) < 2) {
                continue;
            }
            //测试数据库host port是否可连接
            $test_client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
            if (!$test_client->connect($value['serverInfo']['host'], $value['serverInfo']['port'], $value['serverInfo']['timeout'])) {
                throw new MySQLException('connect ' . explode(DB_DELIMITER, $key)[0] .
                      ' ' . explode(DB_DELIMITER, $key)[1] . ' failed, ErrorCode: ' . $test_client->errCode . "\n");
            }
            $test_client->close();
            //初始化连接
            if (!isset($value['startConns'])) {
                $value['startConns'] = 1;
            }
            while ($value['startConns']) {
                //初始化startConns
                $mysql = new \Swoole\Coroutine\MySQL();
                $mysql->connect([
                    'host'     => CONFIG['server']['host'],
                    'user'     => CONFIG['server']['user'],
                    'port'     => CONFIG['server']['port'],
                    'password' => CONFIG['server']['password'],
                    'database' => explode(DB_DELIMITER, $key)[1],
                ]);
                if ($mysql ->connect_errno) {
                    throw new MySQLException(CONFIG['server']['host'] . ':' . CONFIG['server']['port'] . $mysql ->connect_error);
                }
                $mysql->setDefer();
                switch (explode(DB_DELIMITER, $key)[0]) {
                    case 'read':
                        $mysql->query('/*SMProxy test sql*/select sleep(0.1)');
                        break;
                    case 'write':
                        $mysql->query('/*SMProxy test sql*/set autocommit=1');
                        break;
                }
                $clients[] = $mysql;
                $value['startConns']--;
            }
        }
        foreach ($clients as $client) {
            $client->recv();
            if ($client ->errno) {
                throw new MySQLException($client ->error);
            }
            $client->close();
        }
        unset($clients);
    }
}

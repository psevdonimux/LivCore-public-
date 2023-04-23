<?php declare(strict_types=1);

namespace raklib\server;

use pocketmine\snooze\SleeperNotifier;
use raklib\RakLib;
use raklib\utils\InternetAddress;

class RakLibServer extends \Thread{

 private InternetAddress $address;
 protected \ThreadedLogger $logger;
 protected string $loaderPath, $mainPath;
 protected bool $shutdown = false;
 protected \Threaded $externalQueue, $internalQueue;
 protected int $serverId = 0, $maxMtuSize, $protocolVersion;
 protected ?SleeperNotifier $mainThreadNotifier;

public function __construct(\ThreadedLogger $logger, $autoloaderPath, InternetAddress $address, int $maxMtuSize = 1492, ?int $overrideProtocolVersion = null, ?SleeperNotifier $sleeper = null){
$this->address = $address;

$this->serverId = mt_rand(0, PHP_INT_MAX);
$this->maxMtuSize = $maxMtuSize;

$this->logger = $logger;
$this->loaderPath = $autoloaderPath;

$this->externalQueue = new \Threaded;
$this->internalQueue = new \Threaded;

if(\Phar::running(true) !== ''){
$this->mainPath = \Phar::running(true);
}else{
if(($cwd = getcwd()) === false or ($realCwd = realpath($cwd)) === false){
throw new \RuntimeException('Failed to get current working directory');
}
$this->mainPath = $realCwd . DIRECTORY_SEPARATOR;
}

$this->protocolVersion = $overrideProtocolVersion ?? RakLib::DEFAULT_PROTOCOL_VERSION;

$this->mainThreadNotifier = $sleeper;
}

public function isShutdown() : bool{
return $this->shutdown;
}

public function shutdown() : void{
$this->shutdown = true;
}
public function getServerId() : int{
return $this->serverId;
}
public function getProtocolVersion() : int{
return $this->protocolVersion;
}
public function getLogger() : \ThreadedLogger{
return $this->logger;
}
public function getExternalQueue() : \Threaded{
return $this->externalQueue;
}
public function getInternalQueue() : \Threaded{
return $this->internalQueue;
}
public function pushMainToThreadPacket(string $str) : void{
$this->internalQueue[] = $str;
}
public function readMainToThreadPacket() : ?string{
return $this->internalQueue->shift();
}
public function pushThreadToMainPacket(string $str) : void{
$this->externalQueue[] = $str;
if($this->mainThreadNotifier !== null){
$this->mainThreadNotifier->wakeupSleeper();
}
}
public function readThreadToMainPacket() : ?string{
return $this->externalQueue->shift();
}
public function shutdownHandler() : void{
if($this->shutdown !== true){
$error = error_get_last();
if($error !== null){
$this->logger->emergency("Fatal error: " . $error["message"] . " in " . $error["file"] . " on line " . $error["line"]);
}else{
$this->logger->emergency("RakLib shutdown unexpectedly");
}
}
}
public function errorHandler($errno, $errstr, $errfile, $errline) : bool{
if((error_reporting() & $errno) === 0){
return false;
}
$errorConversion = [
E_ERROR => "E_ERROR",
E_WARNING => "E_WARNING",
E_PARSE => "E_PARSE",
E_NOTICE => "E_NOTICE",
E_CORE_ERROR => "E_CORE_ERROR",
E_CORE_WARNING => "E_CORE_WARNING",
E_COMPILE_ERROR => "E_COMPILE_ERROR",
E_COMPILE_WARNING => "E_COMPILE_WARNING",
E_USER_ERROR => "E_USER_ERROR",
E_USER_WARNING => "E_USER_WARNING",
E_USER_NOTICE => "E_USER_NOTICE",
E_STRICT => "E_STRICT",
E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
E_DEPRECATED => "E_DEPRECATED",
E_USER_DEPRECATED => "E_USER_DEPRECATED"
];
$errno = $errorConversion[$errno] ?? $errno;
$errstr = preg_replace('/\s+/', ' ', trim($errstr));
$errfile = $this->cleanPath($errfile);
$this->getLogger()->debug("An $errno error happened: \"$errstr\" in \"$errfile\" at line $errline");
foreach($this->getTrace(2) as $i => $line){
$this->getLogger()->debug($line);
}
return true;
}
public function getTrace($start = 0, $trace = null) : array{
if($trace === null){
if(function_exists("xdebug_get_function_stack")){
$trace = array_reverse(xdebug_get_function_stack());
}else{
$e = new \Exception();
$trace = $e->getTrace();
}
}
$messages = [];
$j = 0;
for($i = $start; isset($trace[$i]); ++$i, ++$j){
$params = "";
if(isset($trace[$i]["args"]) or isset($trace[$i]["params"])){
if(isset($trace[$i]["args"])){
$args = $trace[$i]["args"];
}else{
$args = $trace[$i]["params"];
}
foreach($args as $name => $value){
$params .= (is_object($value) ? get_class($value) . " " . (method_exists($value, "__toString") ? $value->__toString() : "object") : gettype($value) . " " . @strval($value)) . ", ";
}
}
$messages[] = "#$j " . (isset($trace[$i]["file"]) ? $this->cleanPath($trace[$i]["file"]) : "") . "(" . (isset($trace[$i]["line"]) ? $trace[$i]["line"] : "") . "): " . (isset($trace[$i]["class"]) ? $trace[$i]["class"] . (($trace[$i]["type"] === "dynamic" or $trace[$i]["type"] === "->") ? "->" : "::") : "") . $trace[$i]["function"] . "(" . substr($params, 0, -2) . ")";
}
return $messages;
}
public function cleanPath(string $path) : string{
return str_replace(["\\", ".php", "phar://", str_replace(["\\", "phar://"], ["/", ""], $this->mainPath)], ["/", "", "", ""], $path);
}

public function run() : void{
try{
$this->loaderPath->register(true);
gc_enable();
error_reporting(-1);
ini_set("display_errors", '1');
ini_set("display_startup_errors", '1');
set_error_handler([$this, "errorHandler"], E_ALL);
register_shutdown_function([$this, "shutdownHandler"]);
$socket = new UDPServerSocket($this->address);
new SessionManager($this, $socket, $this->maxMtuSize);
}catch(\Throwable $e){
$this->logger->logException($e);
}
}
}
